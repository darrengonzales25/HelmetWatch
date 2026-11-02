// ============================================================
// HelmetWatch Pro — YOLOv8 Webcam & CCTV Detection Engine
// File Location: C:\xampp\htdocs\HelmetViolationWebApp\app.js
// Model: models/best.onnx (4 or 6 classes, Input: 640x640, Output: 1x(4+classes)x8400)
// ============================================================

// ---- Class configs: the app picks one automatically from the model's output size ----
const CLASS_CONFIGS = {
  // Original 6-class model
  6: {
    labels: ['Rider-Helmet', 'Passenger-Helmet', 'Motorbike', 'Rider-No-Helmet', 'Passenger-No-Helmet', 'helmet_substandard'],
    colors: { 0: '#2dc653', 1: '#2dc653', 2: '#f4a261', 3: '#e63946', 4: '#e63946', 5: '#ffbe0b' },
    violations: [3, 4, 5],
    motorbike: 2,
    thresholds: { 0: 0.30, 1: 0.30, 2: 0.35, 3: 0.20, 4: 0.20, 5: 0.20 }
  },
  // Merged 4-class model (rider + passenger combined)
  4: {
    labels: ['Helmet', 'Motorbike', 'No-Helmet', 'helmet_substandard'],
    colors: { 0: '#2dc653', 1: '#f4a261', 2: '#e63946', 3: '#ffbe0b' },
    violations: [2, 3],
    motorbike: 1,
    // Violation classes get a LOWER threshold so weak but real violations are not hidden;
    // the capture rules below stop one-frame false alarms from being captured.
    thresholds: { 0: 0.30, 1: 0.35, 2: 0.20, 3: 0.20 }
  }
};

let LABELS = CLASS_CONFIGS[4].labels;
let CLASS_COLORS = CLASS_CONFIGS[4].colors;
let VIOLATION_IDS = CLASS_CONFIGS[4].violations;
let MOTORBIKE_ID = CLASS_CONFIGS[4].motorbike;
let CLASS_THRESHOLDS = CLASS_CONFIGS[4].thresholds;
let configuredClasses = 0;

function configureClasses(nc) {
  if (configuredClasses === nc) return;
  configuredClasses = nc;
  const cfg = CLASS_CONFIGS[nc];
  if (!cfg) {
    logEvent(`WARNING: model has ${nc} classes but app.js has no config for that. Add one to CLASS_CONFIGS.`, 'err');
    return;
  }
  LABELS = cfg.labels;
  CLASS_COLORS = cfg.colors;
  VIOLATION_IDS = cfg.violations;
  MOTORBIKE_ID = cfg.motorbike;
  CLASS_THRESHOLDS = cfg.thresholds;
  console.log(`Class config loaded: ${nc} classes (${LABELS.join(', ')})`, 'ok');
}

// ---- Models: put your model(s) in the models/ folder. If BOTH files exist, they are combined. ----
// The 4-class and 6-class models are detected automatically from their output size, and every
// detection is converted to the same 4 classes (Helmet, Motorbike, No-Helmet, helmet_substandard).
const MODEL_FILES = ['models/best.onnx', 'models/best2.onnx', 'models/best3.onnx'];
const TO_UNIFIED = {
  4: [0, 1, 2, 3],
  5: [0, 0, 1, 2, 2],
  6: [0, 0, 1, 2, 2, 3]   // Rider/Passenger-Helmet->Helmet, Motorbike, Rider/Passenger-No-Helmet->No-Helmet, substandard
};
let models = [];                 // loaded models: { sess, name, size, nc, map, file }
let activeProvider = 'wasm';
const PROVIDERS = ['webgpu', 'wasm']; // fastest first; falls back automatically
let videoStream = null;
let isRunning = false;
let animFrame = null;
let modelReady = false;

// Tip: open app.php?conf=0.25 to use ONE threshold for every class (overrides the per-class values)
const urlConf = parseFloat(new URLSearchParams(location.search).get('conf')) || null;
const debugMode = new URLSearchParams(location.search).get('debug') === '1';
const DEBUG_VIOLATION_FLOOR = 0.08;
const DEBUG_SHORT = ['H', 'Bk', 'NoH', 'Sub'];
function normalThreshold(classId) {
  return urlConf !== null ? urlConf : (CLASS_THRESHOLDS[classId] ?? 0.30);
}
function thresholdFor(classId) {
  if (debugMode && urlConf === null && VIOLATION_IDS.includes(classId)) return DEBUG_VIOLATION_FLOOR;
  return normalThreshold(classId);
}
// ---- Capture rule ----
// Every detected violation (No-Helmet / helmet_substandard) is captured right away.
// The only limit is the cooldown, so the same rider is not saved again and again.
// A box is DRAWN from its normal threshold (about 20%), but a violation is only SAVED when its score is at
// least CAPTURE_MIN_SCORE. This keeps weak, doubtful detections (24%, 30%...) out of the archive.
// Open app.php?cap=0.30 to change it for one session, or edit the number here. 0.20 = save everything.
const urlCap = parseFloat(new URLSearchParams(location.search).get('cap'));
const CAPTURE_MIN_SCORE = (urlCap > 0 && urlCap < 1) ? urlCap : 0.40;
const iouThreshold = 0.45;
// Where the camera is placed (printed on every captured photo and shown in the archive).
// Type your place here, e.g. 'Brgy. San Jose - Main Gate'. If left empty, the selected camera's name is used.
const LOCATION_NAME = '';
// If LOCATION_NAME is empty, the place is taken from the browser's location (the user allows it once):
// "Barangay, City, Province" e.g. "Mayapa, Calamba City, Laguna".
let geoPlace = '';
const GEO_KEY = 'helmetwatch_place';
async function initLocation() {
  if (LOCATION_NAME) return;
  try { geoPlace = localStorage.getItem(GEO_KEY) || ''; } catch (e) { /* ignore */ }
  if (!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(async (pos) => {
    try {
      const { latitude, longitude } = pos.coords;
      const r = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=16&addressdetails=1&accept-language=en&lat=' + latitude + '&lon=' + longitude);
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const ad = (await r.json()).address || {};
      const brgy = ad.suburb || ad.neighbourhood || ad.quarter || ad.village || ad.hamlet || ad.residential || '';
      let city = ad.city || ad.town || ad.municipality || ad.city_district || '';
      if (ad.city && city && !/city$/i.test(city)) city += ' City';
      const prov = ad.county || ad.state_district || ad.province || ad.state || '';
      const place = [brgy, city, prov].filter(Boolean).join(', ');
      if (place) {
        geoPlace = place;
        try { localStorage.setItem(GEO_KEY, place); } catch (e) { /* ignore */ }
        logEvent('Location: ' + place, 'ok');
      }
    } catch (e) {
      logEvent('Could not read the place name: ' + e.message, 'err');
    }
  }, (err) => {
    logEvent('Location not shared, so photos will use the camera name. Allow location in the browser to show the barangay/city/province.', '');
  }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 600000 });
}
const PH_TZ = 'Asia/Manila';
function phTime(withDate = true) {
  return new Date().toLocaleString('en-PH', withDate
    ? { timeZone: PH_TZ, year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }
    : { timeZone: PH_TZ, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
}
function captureLocation() {
  if (LOCATION_NAME) return LOCATION_NAME;
  if (geoPlace) return geoPlace;
  const sel = document.getElementById('cam-select');
  const opt = sel && sel.selectedOptions && sel.selectedOptions[0];
  return opt && opt.value ? opt.textContent.trim() : '';
}
let autoCooldown = 5;        // Seconds between violation captures
let lastCaptureTime = 0;

// Offscreen canvas with read optimization (resized to each model's input size)
const inputCanvas = document.createElement('canvas');
inputCanvas.width = 640;
inputCanvas.height = 640;
const inputCtx = inputCanvas.getContext('2d', { willReadFrequently: true });

// ------------------------------------------------------------
// 1. SYSTEM INITIALIZATION & MODEL LOADING
// ------------------------------------------------------------
async function initSystem() {
  console.log('Initializing HelmetWatch Engine...', 'ok');
  // Cameras load in parallel: the permission prompt must not delay the model
  populateCameras();

  try {
    console.log('Loading YOLOv8 ONNX model(s)...', 'ok');
    await createSession();

    const overlay = document.getElementById('model-overlay');
    if (overlay) overlay.style.display = 'none';

    modelReady = true;
    setStatus('READY', 'active');
    logEvent(models.length > 1 ? `Model ready (${models.length} models combined).` : 'Model ready.', 'ok');
    logEvent(debugMode ? 'Debug mode: all class scores shown, dashed boxes are below the normal threshold, captures are OFF.' : `Capture mode: violations of ${Math.round(CAPTURE_MIN_SCORE * 100)}% or more (cooldown ${autoCooldown} s).`, 'ok');
  } catch (e) {
    logEvent('Failed to load ONNX model: ' + e.message, 'err');
    const overlay = document.getElementById('model-overlay');
    if (overlay) {
      overlay.innerHTML = '<p style="color:var(--red); font-family:var(--font-mono); font-size:12px;">❌ Model Load Error: Put best.onnx, best2.onnx or best3.onnx in the models folder!</p>';
    }
  }
}


// Try the fastest execution provider first (WebGPU), fall back to WASM (CPU).
// A warm-up run is done so a provider that loads but fails on the first frame is skipped.
function detectInputSize(sess) {
  try {
    const meta = sess.inputMetadata;
    const first = Array.isArray(meta) ? meta[0] : (meta && meta[sess.inputNames[0]]);
    const h = first && first.shape && first.shape[2];
    if (Number.isInteger(h) && h >= 160 && h <= 1280) return h;
  } catch (e) { /* use default */ }
  return 640;
}

let modelManifest = null;
const modelBytesCache = {};

async function getModelSource(file) {
  if (modelBytesCache[file]) return modelBytesCache[file];
  if (modelManifest === null) {
    try {
      const r = await fetch('models/manifest.json', { cache: 'no-cache' });
      modelManifest = r.ok ? await r.json() : {};
    } catch (e) {
      modelManifest = {};
    }
  }
  const parts = modelManifest[file];
  if (!parts || !parts.length) return file;
  const buffers = [];
  let total = 0;
  for (const p of parts) {
    const r = await fetch(p, { cache: 'no-cache' });
    if (!r.ok) throw new Error('Could not download ' + p + ' (HTTP ' + r.status + ')');
    const b = new Uint8Array(await r.arrayBuffer());
    // A web page (e.g. the host's error/notice page) instead of the model piece?
    const head = new TextDecoder('latin1').decode(b.subarray(0, 64)).trim().toLowerCase();
    if (head.startsWith('<!doctype') || head.startsWith('<html') || head.startsWith('<script') || /text\/html/i.test(r.headers.get('content-type') || '')) {
      throw new Error(p + ' is a web page, not a model file (the server did not send the real file)');
    }
    if (buffers.length && b.length !== buffers[0].length && parts.indexOf(p) !== parts.length - 1) {
      throw new Error(p + ' has ' + b.length + ' bytes but the first part has ' + buffers[0].length + ' (upload cut off?)');
    }
    console.log('Downloaded ' + p.split('/').pop() + ' (' + b.length + ' bytes)');
    buffers.push(b);
    total += b.length;
  }
  const all = new Uint8Array(total);
  let offset = 0;
  for (const b of buffers) {
    all.set(b, offset);
    offset += b.length;
  }
  modelBytesCache[file] = all;
  return all;
}

async function loadOneModel(file, ep) {
  const source = await getModelSource(file);
  const sess = await ort.InferenceSession.create(source, {
    executionProviders: [ep],
    graphOptimizationLevel: 'all'
  });
  // ?size=960 forces the input size if the model's size cannot be read automatically
  const forcedSize = parseInt(new URLSearchParams(location.search).get('size'), 10);
  const size = (forcedSize >= 160 && forcedSize <= 1536) ? forcedSize : detectInputSize(sess);
  const name = (sess.inputNames && sess.inputNames[0]) || 'images';
  const dummy = new ort.Tensor('float32', new Float32Array(3 * size * size), [1, 3, size, size]);
  const t0 = performance.now();
  const out = await sess.run({ [name]: dummy });
  const ms = Math.round(performance.now() - t0);
  const first = out[(sess.outputNames && sess.outputNames[0]) || Object.keys(out)[0]];
  const nc = first.dims[1] - 4;
  if (!TO_UNIFIED[nc]) throw new Error(`${file} has ${nc} classes (only 4, 5 or 6 are supported)`);
  console.log(`Model ${file}: ${ep.toUpperCase()} | input ${size}x${size} | ${nc} classes | first run ${ms} ms`);
  return { sess, name, size, nc, map: TO_UNIFIED[nc], file };
}

async function createSession() {
  try {
    ort.env.wasm.numThreads = Math.min(4, navigator.hardwareConcurrency || 2);
  } catch (e) { /* ignore */ }

  // The result is always the 4-class set
  configureClasses(4);

  let lastError = null;
  for (const ep of PROVIDERS) {
    try {
      const loaded = [];
      for (let i = 0; i < MODEL_FILES.length; i++) {
        try {
          loaded.push(await loadOneModel(MODEL_FILES[i], ep));
        } catch (e) {
          lastError = e;
          console.log(`Optional model ${MODEL_FILES[i]} skipped: ${e.message || e}`);
          logEvent(`${MODEL_FILES[i]} not loaded: ${e.message || e}`, 'err');
        }
      }
      if (!loaded.length) throw lastError || new Error('No model could be loaded');
      models = loaded;
      activeProvider = ep;
      return;
    } catch (e) {
      lastError = e;
      console.log(`${ep.toUpperCase()} not usable (${e.message || e}) - trying next option...`);
    }
  }
  throw lastError || new Error('No execution provider could run the model');
}

const CAMERA_KEY = 'helmetwatch_camera';
async function onCameraChange() {
  const sel = document.getElementById('cam-select');
  try { localStorage.setItem(CAMERA_KEY, sel.value); } catch (e) { /* ignore */ }
  if (!isRunning) return;
  try {
    const next = await navigator.mediaDevices.getUserMedia({ video: { deviceId: sel.value ? { exact: sel.value } : undefined, width: { ideal: 1280 }, height: { ideal: 720 } } });
    const old = videoStream;
    videoStream = next;
    const video = document.getElementById('video');
    video.srcObject = next;
    await video.play();
    if (old) old.getTracks().forEach(t => t.stop());
    logEvent('Camera switched.', 'ok');
  } catch (e) { logEvent('Could not switch camera: ' + e.message, 'err'); }
}

async function populateCameras() {
  try {
    await navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
      stream.getTracks().forEach(track => track.stop());
    }).catch(() => {});

    const devices = await navigator.mediaDevices.enumerateDevices();
    const cams = devices.filter(d => d.kind === 'videoinput');
    const sel = document.getElementById('cam-select');
    if (!sel) return;

    sel.innerHTML = '<option value="">— select camera —</option>';
    cams.forEach((cam, i) => {
      const opt = document.createElement('option');
      opt.value = cam.deviceId;
      opt.textContent = cam.label || `Camera Device ${i + 1}`;
      sel.appendChild(opt);
    });
    // Remember the chosen camera
    try {
      const saved = localStorage.getItem(CAMERA_KEY);
      if (saved && cams.some(c => c.deviceId === saved)) sel.value = saved;
    } catch (e) { /* ignore */ }
    if (!sel.dataset.bound) { sel.addEventListener('change', onCameraChange); sel.dataset.bound = '1'; }
  } catch (e) {
    logEvent('Camera enumeration error: ' + e.message, 'err');
  }
}

// ------------------------------------------------------------
// 2. WEBCAM & VIDEO STREAM CONTROLS
// ------------------------------------------------------------
async function startCamera() {
  if (!modelReady) {
    alert("Model is still loading, please wait...");
    return;
  }

  try {
    const camSelect = document.getElementById('cam-select');
    const camId = camSelect ? camSelect.value : '';
    const constraints = {
      video: {
        deviceId: camId ? { exact: camId } : undefined,
        width: { ideal: 1280 },
        height: { ideal: 720 }
      }
    };

    videoStream = await navigator.mediaDevices.getUserMedia(constraints);
    const video = document.getElementById('video');
    video.srcObject = videoStream;
    await video.play();

    const placeholder = document.getElementById('cam-placeholder');
    if (placeholder) placeholder.style.display = 'none';

    const btnStart = document.getElementById('btn-start');
    const btnStop = document.getElementById('btn-stop');
    if (btnStart) btnStart.disabled = true;
    if (btnStop) btnStop.disabled = false;

    setStatus('ACTIVE', 'active');
    isRunning = true;
    fpsStart();
    detectLoop();
    logEvent('Camera started.', 'ok');
  } catch (e) {
    logEvent('Camera error: ' + e.message, 'err');
  }
}

function stopCamera() {
  isRunning = false;
  fpsStop();
  if (animFrame) cancelAnimationFrame(animFrame);
  if (videoStream) {
    videoStream.getTracks().forEach(t => t.stop());
    videoStream = null;
  }
  const video = document.getElementById('video');
  if (video) {
    video.pause();
    video.srcObject = null;
  }

  const placeholder = document.getElementById('cam-placeholder');
  if (placeholder) placeholder.style.display = 'flex';

  const btnStart = document.getElementById('btn-start');
  const btnStop = document.getElementById('btn-stop');
  if (btnStart) btnStart.disabled = false;
  if (btnStop) btnStop.disabled = true;

  setStatus('OFFLINE', '');
  logEvent('Camera stopped.', '');
}

// ------------------------------------------------------------
// FPS COUNTER (badge on the video, top-left)
// DET = how many frames per second the AI finishes analysing
// CAM = how many frames per second the camera/video delivers
// ------------------------------------------------------------
let _fpsDet = 0, _fpsCam = 0, _fpsLast = 0, _fpsMs = 0, _fpsBadge = null, _fpsVfc = 0;
function fpsBadge() {
  if (_fpsBadge && _fpsBadge.isConnected) return _fpsBadge;
  const box = document.getElementById('camera-container');
  if (!box) return null;
  _fpsBadge = document.createElement('div');
  _fpsBadge.id = 'fps-badge';
  _fpsBadge.style.cssText = 'position:absolute;top:12px;left:12px;z-index:10;padding:5px 9px;' +
    'background:rgba(0,0,0,0.65);color:#2dc653;font:11px/1.4 monospace;border-radius:4px;' +
    'pointer-events:none;white-space:pre;display:none;';
  box.appendChild(_fpsBadge);
  return _fpsBadge;
}
function fpsStart() {
  _fpsDet = _fpsCam = _fpsMs = 0; _fpsLast = performance.now();
  const video = document.getElementById('video');
  if (video && video.requestVideoFrameCallback) {
    const tick = () => { _fpsCam++; _fpsVfc = video.requestVideoFrameCallback(tick); };
    _fpsVfc = video.requestVideoFrameCallback(tick);
  }
  const b = fpsBadge(); if (b) { b.textContent = 'DET -- FPS'; b.style.display = 'block'; }
}
function fpsStop() {
  const video = document.getElementById('video');
  if (video && video.cancelVideoFrameCallback && _fpsVfc) video.cancelVideoFrameCallback(_fpsVfc);
  _fpsVfc = 0;
  if (_fpsBadge) _fpsBadge.style.display = 'none';
}
function fpsTick(inferMs) {
  _fpsDet++; _fpsMs = inferMs;
  const now = performance.now();
  if (now - _fpsLast < 1000) return;
  const sec = (now - _fpsLast) / 1000;
  const det = _fpsDet / sec, cam = _fpsCam / sec;
  const b = fpsBadge();
  if (b) {
    b.style.color = det >= 15 ? '#2dc653' : det >= 8 ? '#ffbe0b' : '#e63946';
    b.textContent = `DET ${det.toFixed(1)} FPS` + (_fpsCam ? `\nCAM ${cam.toFixed(0)} FPS` : '') + `\nAI  ${Math.round(inferMs)} ms · ${activeProvider.toUpperCase()}` + (models.length > 1 ? ` · ${models.length} models` : '');
  }
  _fpsDet = 0; _fpsCam = 0; _fpsLast = now;
}

// ------------------------------------------------------------
// 3. REAL-TIME DETECTION LOOP & LETTERBOX PREPROCESSING
// ------------------------------------------------------------
async function detectLoop() {
  if (!isRunning) return;

  const video = document.getElementById('video');
  const canvas = document.getElementById('overlay-canvas');

  if (!video || video.readyState < video.HAVE_CURRENT_DATA) {
    animFrame = requestAnimationFrame(detectLoop);
    return;
  }

  canvas.width = video.videoWidth || 640;
  canvas.height = video.videoHeight || 480;

  try {
    const t0 = performance.now();
    let rawDetections = [];
    const tensors = {};                       // models with the same input size share one preprocessed frame
    for (const m of models) {
      if (!tensors[m.size]) tensors[m.size] = preprocessFrame(video, m.size);
      const tensor = tensors[m.size];
      if (!tensor) {
        animFrame = requestAnimationFrame(detectLoop);
        return;
      }
      const outputMap = await m.sess.run({ [m.name]: tensor });
      const key = (m.sess.outputNames && m.sess.outputNames.length > 0) ? m.sess.outputNames[0] : Object.keys(outputMap)[0];
      const out = outputMap[key];             // dims = [1, 4 + numClasses, numAnchors]
      rawDetections = rawDetections.concat(postprocessYOLOv8(
        out.data, out.dims, canvas.width, canvas.height, video.videoWidth, video.videoHeight, m
      ));
    }
    const _inferMs = performance.now() - t0;
    trackLatency(_inferMs);
    fpsTick(_inferMs);

    // Class-wise NMS also removes the same box found twice by the two models
    const merged = nms(rawDetections, iouThreshold);
    const detections = debugMode ? merged : keepViolationsOnMotorbikes(refineDetections(merged));

    renderDetections(detections, canvas);
    if (!debugMode) checkAutoCapture(canvas, detections);

  } catch (e) {
    console.error("Inference Error:", e);
  }

  animFrame = requestAnimationFrame(detectLoop);
}

// Preprocess video frame with letterbox padding & explicit 4D tensor shape
// Logs the average inference time every 5 seconds so you can see the real speed
let _latSum = 0, _latN = 0, _latLast = 0;
function trackLatency(ms) {
  _latSum += ms; _latN++;
  const now = Date.now();
  if (now - _latLast >= 5000) {
    const avg = _latSum / _latN;
    console.log(`Speed: ${Math.round(avg)} ms per frame (~${(1000 / avg).toFixed(1)} FPS) on ${activeProvider.toUpperCase()}`, '');
    _latSum = 0; _latN = 0; _latLast = now;
  }
}

function preprocessFrame(video, S) {
  if (!video || video.readyState < 2 || video.videoWidth === 0) {
    return null;
  }

  const vW = video.videoWidth;
  const vH = video.videoHeight;

  // Calculate letterbox scaling factor
  if (inputCanvas.width !== S) { inputCanvas.width = S; inputCanvas.height = S; }
  const r = Math.min(S / vW, S / vH);
  const nW = Math.round(vW * r);
  const nH = Math.round(vH * r);
  const dx = Math.floor((S - nW) / 2);
  const dy = Math.floor((S - nH) / 2);

  // Fill padding background with standard YOLO letterbox gray
  inputCtx.fillStyle = '#727272';
  inputCtx.fillRect(0, 0, S, S);

  // Draw scaled video frame centered on offscreen canvas
  inputCtx.drawImage(video, dx, dy, nW, nH);

  const imgData = inputCtx.getImageData(0, 0, S, S);
  const data = imgData.data;

  const float32Data = new Float32Array(1 * 3 * S * S);

  // Pixel normalization (0 to 1) in NCHW layout
  const plane = S * S;
  for (let i = 0; i < plane; i++) {
    float32Data[i] = data[i * 4] / 255.0;                     // Red
    float32Data[plane + i] = data[i * 4 + 1] / 255.0;     // Green
    float32Data[2 * plane + i] = data[i * 4 + 2] / 255.0;   // Blue
  }

  // Explicit Rank-4 Tensor Shape: [batch, channels, height, width]
  const tensorShape = new Array(1, 3, S, S);
  return new ort.Tensor('float32', float32Data, tensorShape);
}

// Parse YOLOv8 Output shape (1 x 9 x 8400) and un-letterbox coordinates
function postprocessYOLOv8(outputData, dims, imgWidth, imgHeight, videoWidth, videoHeight, m) {
  const boxes = [];
  const numAnchors = dims[2];          // normally 8400
  const numClasses = dims[1] - 4;      // 4 (merged model) or 6 (original model)
  const S = m.size;
  const map = m.map;                   // this model's class id -> unified class id
  // Re-calculate letterbox offsets
  const r = Math.min(S / videoWidth, S / videoHeight);
  const nW = Math.round(videoWidth * r);
  const nH = Math.round(videoHeight * r);
  const dx = Math.floor((S - nW) / 2);
  const dy = Math.floor((S - nH) / 2);

  // Besides the best class of each anchor, a VIOLATION class is also kept when it is nearly as
  // strong. Before, an anchor scoring Helmet 0.30 / No-Helmet 0.28 was reported only as "Helmet"
  // and the no-helmet evidence was thrown away. 0.5 means "at least half as strong as the best".
  const SECOND_BEST_RATIO = 0.3;

  for (let i = 0; i < numAnchors; i++) {
    let maxClassScore = -Infinity;
    let maxClassId = -1;

    for (let c = 0; c < numClasses; c++) {
      const score = outputData[(4 + c) * numAnchors + i];
      if (score > maxClassScore) {
        maxClassScore = score;
        maxClassId = c;
      }
    }

    for (let c = 0; c < numClasses; c++) {
      const score = outputData[(4 + c) * numAnchors + i];
      const uid = map[c];
      if (c !== maxClassId) {
        if (!VIOLATION_IDS.includes(uid)) continue;
        if (!debugMode && score < maxClassScore * SECOND_BEST_RATIO) continue;
      }
      if (score < thresholdFor(uid)) continue;

      const cx = outputData[0 * numAnchors + i];
      const cy = outputData[1 * numAnchors + i];
      const w = outputData[2 * numAnchors + i];
      const h = outputData[3 * numAnchors + i];

      // Remove letterbox offsets and map back to display canvas dimensions
      const x1 = Math.max(0, ((cx - w / 2 - dx) / nW) * imgWidth);
      const y1 = Math.max(0, ((cy - h / 2 - dy) / nH) * imgHeight);
      const x2 = Math.min(imgWidth, ((cx + w / 2 - dx) / nW) * imgWidth);
      const y2 = Math.min(imgHeight, ((cy + h / 2 - dy) / nH) * imgHeight);

      let scores = null;
      if (debugMode) {
        scores = new Array(LABELS.length).fill(0);
        for (let k = 0; k < numClasses; k++) {
          const sk = outputData[(4 + k) * numAnchors + i];
          if (sk > scores[map[k]]) scores[map[k]] = sk;
        }
      }

      boxes.push({
        scores: scores,
        x1: x1,
        y1: y1,
        x2: x2,
        y2: y2,
        classId: uid,
        className: LABELS[uid],
        score: score
      });
    }
  }

  return boxes;
}

// ------------------------------------------------------------
// 4. NON-MAXIMUM SUPPRESSION (NMS)
// ------------------------------------------------------------
function nms(boxes, iouThresh) {
  boxes.sort((a, b) => b.score - a.score);
  const selected = [];
  const active = new Array(boxes.length).fill(true);

  for (let i = 0; i < boxes.length; i++) {
    if (active[i]) {
      const boxA = boxes[i];
      selected.push(boxA);

      for (let j = i + 1; j < boxes.length; j++) {
        if (active[j]) {
          const boxB = boxes[j];
          if (boxA.classId === boxB.classId && calculateIoU(boxA, boxB) > iouThresh) {
            active[j] = false;
          }
        }
      }
    }
  }

  return selected;
}

function calculateIoU(a, b) {
  const x1 = Math.max(a.x1, b.x1);
  const y1 = Math.max(a.y1, b.y1);
  const x2 = Math.min(a.x2, b.x2);
  const y2 = Math.min(a.y2, b.y2);

  const intersection = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
  const areaA = (a.x2 - a.x1) * (a.y2 - a.y1);
  const areaB = (b.x2 - b.x1) * (b.y2 - b.y1);

  return intersection / (areaA + areaB - intersection);
}

// ------------------------------------------------------------
// 5. MOTORCYCLE INTERSECTION & COMPOSITE AUTO-CAPTURE
// ------------------------------------------------------------
const MIN_BIKE_OVERLAP = 0.25;
const SAME_RIDER_IOU = 0.3;
const SAVE_GAP_MS = 1100;
const recentCaptures = [];
const captureQueue = [];
let queueBusy = false;

function overlapArea(a, b) {
  const w = Math.min(a.x2, b.x2) - Math.max(a.x1, b.x1);
  const h = Math.min(a.y2, b.y2) - Math.max(a.y1, b.y1);
  return w > 0 && h > 0 ? w * h : 0;
}

const INNER_OVERLAP = 0.8;
const BODY_TO_HEAD_RATIO = 0.6;
const DUPLICATE_OVERLAP = 0.7;
const HELMET_CONFLICT_OVERLAP = 0.5;
const HELMET_WIN_MARGIN = 0.15;
const VIOLATION_ALWAYS_KEEP = 0.6;
const WRONG_HELMET_RELABEL = true;
const SHOW_WEAK_VIOLATIONS = false;
const WRONG_HELMET_SAME_SIZE = 0.35;
const WRONG_HELMET_SAME_OVERLAP = 0.45;
const WRONG_HELMET_MAX_RATIO = 0.65;
const WRONG_HELMET_INSIDE = 0.35;
const WRONG_HELMET_TOP_PART = 0.5;

function boxArea(d) {
  return Math.max(0, d.x2 - d.x1) * Math.max(0, d.y2 - d.y1);
}

function refineDetections(dets) {
  const heads = dets.filter(d => d.classId !== MOTORBIKE_ID);
  const others = dets.filter(d => d.classId === MOTORBIKE_ID);
  const drop = new Set();

  for (let i = 0; i < heads.length; i++) {
    for (let j = i + 1; j < heads.length; j++) {
      const a = heads[i], b = heads[j];
      if (a.classId !== b.classId || drop.has(a) || drop.has(b)) continue;
      const areaA = boxArea(a), areaB = boxArea(b);
      if (areaA <= 0 || areaB <= 0) continue;
      const small = areaA <= areaB ? a : b;
      const large = small === a ? b : a;
      const smallArea = Math.min(areaA, areaB);
      const largeArea = Math.max(areaA, areaB);
      const inside = overlapArea(a, b) / smallArea;
      if (inside >= INNER_OVERLAP && smallArea / largeArea < BODY_TO_HEAD_RATIO) {
        drop.add(large);
      } else if (inside >= DUPLICATE_OVERLAP) {
        drop.add(a.score >= b.score ? b : a);
      }
    }
  }

  let kept = heads.filter(d => !drop.has(d));

  if (WRONG_HELMET_RELABEL) {
    const NO_HELMET_ID = LABELS.indexOf('No-Helmet');
    const SUBSTANDARD_ID = LABELS.indexOf('helmet_substandard');
    if (NO_HELMET_ID >= 0 && SUBSTANDARD_ID >= 0) {
      const used = new Set();
      kept = kept.map(d => {
        if (d.classId !== NO_HELMET_ID || d.score < CAPTURE_MIN_SCORE) return d;
        const area = boxArea(d);
        const bh = d.y2 - d.y1;
        if (area <= 0 || bh <= 0) return d;
        const matches = kept.filter(h => {
          if (h.classId === NO_HELMET_ID || VIOLATION_IDS.includes(h.classId) || used.has(h)) return false;
          if (h.score >= VIOLATION_ALWAYS_KEEP) return false;
          const ha = boxArea(h);
          if (ha <= 0) return false;
          const sameRider = Math.min(ha, area) / Math.max(ha, area) >= WRONG_HELMET_SAME_SIZE &&
            overlapArea(d, h) / Math.min(ha, area) >= WRONG_HELMET_SAME_OVERLAP &&
            h.score < d.score + HELMET_WIN_MARGIN;
          if (sameRider) return true;
          if (ha / area > WRONG_HELMET_MAX_RATIO) return false;
          if (overlapArea(d, h) / ha < WRONG_HELMET_INSIDE) return false;
          const cx = (h.x1 + h.x2) / 2;
          const cy = (h.y1 + h.y2) / 2;
          const tol = (d.x2 - d.x1) * 0.2;
          if (cx < d.x1 - tol || cx > d.x2 + tol) return false;
          const rel = (cy - d.y1) / bh;
          return rel >= -0.25 && rel <= WRONG_HELMET_TOP_PART;
        });
        if (!matches.length) return d;
        matches.forEach(h => { used.add(h); drop.add(h); });
        return Object.assign({}, d, { classId: SUBSTANDARD_ID, className: LABELS[SUBSTANDARD_ID] });
      });
      kept = kept.filter(d => !drop.has(d));
    }
  }

  const helmets = kept.filter(d => !VIOLATION_IDS.includes(d.classId));

  const result = kept.filter(d => {
    if (!VIOLATION_IDS.includes(d.classId)) return true;
    if (d.score >= VIOLATION_ALWAYS_KEEP) return true;
    const area = boxArea(d);
    if (area <= 0) return false;
    return !helmets.some(h =>
      overlapArea(d, h) / Math.min(area, boxArea(h)) >= HELMET_CONFLICT_OVERLAP &&
      h.score >= d.score + HELMET_WIN_MARGIN
    );
  });

  const shown = SHOW_WEAK_VIOLATIONS ? result : result.filter(d => !VIOLATION_IDS.includes(d.classId) || d.score >= CAPTURE_MIN_SCORE);

  return shown.concat(others);
}

function keepViolationsOnMotorbikes(dets) {
  const bikes = dets.filter(d => d.classId === MOTORBIKE_ID);
  return dets.filter(d => {
    if (!VIOLATION_IDS.includes(d.classId)) return true;
    const area = (d.x2 - d.x1) * (d.y2 - d.y1);
    if (area <= 0) return false;
    return bikes.some(b => overlapArea(d, b) / area >= MIN_BIKE_OVERLAP);
  });
}

function checkAutoCapture(canvas, detections) {
  const now = Date.now() / 1000;
  for (let i = recentCaptures.length - 1; i >= 0; i--) {
    if (now - recentCaptures[i].time >= autoCooldown) recentCaptures.splice(i, 1);
  }

  const fresh = [];
  const violations = detections
    .filter(d => VIOLATION_IDS.includes(d.classId) && d.score >= CAPTURE_MIN_SCORE)
    .sort((a, b) => b.score - a.score);

  for (const d of violations) {
    const known = recentCaptures.find(r => calculateIoU(r.box, d) > SAME_RIDER_IOU);
    if (known) {
      known.box = d;
    } else {
      const record = { box: d, time: now };
      recentCaptures.push(record);
      fresh.push({ violation: d, record });
    }
  }
  if (!fresh.length) return;

  const video = document.getElementById('video');
  const dataUrl = takeSnapshot(video, canvas);
  for (const f of fresh) captureQueue.push({ dataUrl, violation: f.violation, record: f.record });
  pumpQueue();
}

function pumpQueue() {
  if (queueBusy || !captureQueue.length) return;
  queueBusy = true;
  const job = captureQueue.shift();
  uploadCapture(job.dataUrl, job.violation, job.record).finally(() => {
    setTimeout(() => { queueBusy = false; pumpQueue(); }, SAVE_GAP_MS);
  });
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function takeSnapshot(video, overlayCanvas) {
  const snapCanvas = document.createElement('canvas');
  snapCanvas.width = video.videoWidth || overlayCanvas.width || 640;
  snapCanvas.height = video.videoHeight || overlayCanvas.height || 480;
  const snapCtx = snapCanvas.getContext('2d');
  snapCtx.drawImage(video, 0, 0, snapCanvas.width, snapCanvas.height);
  snapCtx.drawImage(overlayCanvas, 0, 0, snapCanvas.width, snapCanvas.height);

  // Time stamp (Philippine Time) + location, printed on the photo
  const loc = captureLocation();
  const stamp = phTime(true) + ' PHT' + (loc ? '  |  ' + loc : '');
  const fs = Math.max(14, Math.round(snapCanvas.width / 55));
  snapCtx.font = `bold ${fs}px Arial, sans-serif`;
  const bh = fs + 14;
  snapCtx.fillStyle = 'rgba(0,0,0,0.62)';
  snapCtx.fillRect(0, snapCanvas.height - bh, snapCanvas.width, bh);
  snapCtx.fillStyle = '#ffffff';
  snapCtx.textBaseline = 'middle';
  snapCtx.fillText(stamp, 10, snapCanvas.height - bh / 2, snapCanvas.width - 20);
  return snapCanvas.toDataURL('image/jpeg', 0.85);
}

function uploadCapture(dataUrl, violation, record) {
  logEvent(`VIOLATION: ${violation.className} (${Math.round(violation.score * 100)}%)`, 'err');

  const formData = new FormData();
  formData.append('image', dataUrl);
  formData.append('violation_type', violation.className);
  formData.append('confidence', violation.score.toFixed(2));
  formData.append('location', captureLocation());

  const retryLater = () => { record.time = Date.now() / 1000 - autoCooldown + 3; };

  return fetch('save_capture.php', { method: 'POST', body: formData, credentials: 'same-origin' })
    .then(res => res.text().then(text => ({ ok: res.ok, status: res.status, text })))
    .then(({ ok, status, text }) => {
      if (ok && /Success\s*$/.test(text)) {
        logEvent('Violation saved.', 'ok');
        const statCap = document.getElementById('stat-captures');
        if (statCap) {
          statCap.textContent = parseInt(statCap.textContent || '0', 10) + 1;
        }
      } else {
        retryLater();
        logEvent(`Save error (HTTP ${status}): ${escapeHtml(text.trim().slice(0, 200))}`, 'err');
      }
    })
    .catch(err => {
      retryLater();
      logEvent(`Upload failed: ${escapeHtml(err.message)}`, 'err');
    });
}

// ------------------------------------------------------------
// 6. DRAW OVERLAY & UTILITIES
// ------------------------------------------------------------
function renderDetections(dets, canvas) {
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  let hasViolation = false;

  dets.forEach(det => {
    const isViolation = VIOLATION_IDS.includes(det.classId);
    const weak = isViolation && det.score < CAPTURE_MIN_SCORE;   // drawn dashed, not saved
    if (isViolation && !weak) hasViolation = true;

    const color = CLASS_COLORS[det.classId] || '#00b4d8';
    const w = det.x2 - det.x1;
    const h = det.y2 - det.y1;

    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.setLineDash(weak || (debugMode && det.score < normalThreshold(det.classId)) ? [6, 4] : []);
    ctx.strokeRect(det.x1, det.y1, w, h);
    ctx.setLineDash([]);

    ctx.fillStyle = color;
    let labelText = det.className + ' ' + Math.round(det.score * 100) + '%';
    if (debugMode && det.scores) {
      labelText += '  ' + det.scores.map((v, i) => (DEBUG_SHORT[i] || i) + Math.round(v * 100)).join(' ');
    }
    ctx.font = '12px "Space Mono", monospace';
    const textWidth = ctx.measureText(labelText).width;

    ctx.fillRect(det.x1, Math.max(0, det.y1 - 20), textWidth + 10, 20);
    ctx.fillStyle = '#000000';
    ctx.fillText(labelText, det.x1 + 5, Math.max(14, det.y1 - 5));
  });

  const banner = document.getElementById('alert-banner');
  if (banner) {
    banner.style.display = hasViolation ? 'block' : 'none';
  }
}

function logEvent(msg, type = '') {
  const log = document.getElementById('event-log');
  if (!log) return;
  const ts = phTime(false);
  log.insertAdjacentHTML('beforeend', `<div class="log-entry"><span class="ts">${ts}</span> <span class="${type}">${msg}</span></div>`);
  while (log.childElementCount > 150) log.removeChild(log.firstChild);
  log.scrollTop = log.scrollHeight;
}

function setStatus(text, cls) {
  const el = document.getElementById('system-status');
  if (el) {
    el.textContent = text.charAt(0) + text.slice(1).toLowerCase();
    el.className = 'pill system-status-pill ' + cls;
  }
}

// Start loading the model right away (while the loading screen is showing),
// not after the page 'load' event. transitions.js waits for this promise.
window.hwModelReady = initSystem();
initLocation();
