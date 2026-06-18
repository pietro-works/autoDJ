const stateEls = {
  shell: document.querySelector(".autodj-shell"),
  form: document.querySelector("[data-vibe-form]"),
  vibe: document.querySelector("#vibe"),
  notice: document.querySelector("[data-notice]"),
  title: document.querySelector("[data-scramble-title]"),
  logoRotors: Array.from(document.querySelectorAll(".logo-rotor")),
  current: document.querySelector("[data-current-track]"),
  feed: document.querySelector("[data-feed]"),
  login: document.querySelector("[data-login-link]"),
  toggle: document.querySelector("[data-toggle-autodj]"),
  toggleLabel: document.querySelector("[data-toggle-label]")
};

let isAdmin = false;
let isConfigured = false;
let isBusy = false;
let isRunning = false;
let rotorAngle = 0;
let rotorVelocity = 0;
let rotorTargetVelocity = 0;
let rotorFrame = null;
let lastRotorAt = null;
let titleScrambleTimer = null;
let heartbeatInFlight = false;

function text(value) {
  return value == null || value === "" ? "-" : String(value);
}

function showNotice(message) {
  stateEls.notice.textContent = message;
  stateEls.notice.hidden = !message;
}

function trackMarkup(track, meta) {
  if (!track) {
    return '<span class="empty">No track queued yet.</span>';
  }
  const image = track.image_url
    ? `<img class="cover" src="${escapeHtml(track.image_url)}" alt="">`
    : '<span class="cover"></span>';
  const linkOpen = track.spotify_url ? `<a href="${escapeHtml(track.spotify_url)}" target="_blank" rel="noopener">` : "<span>";
  const linkClose = track.spotify_url ? "</a>" : "</span>";
  return `
    <div class="track">
      ${image}
      <div>
        ${linkOpen}<span class="track-title">${escapeHtml(text(track.name))}</span>${linkClose}
        <span class="track-artist">${escapeHtml(text(track.artists))}</span>
        <div class="track-meta">${escapeHtml(meta || "")}</div>
      </div>
    </div>
  `;
}

function queuedTracks(payload) {
  const spotifyQueue = Array.isArray(payload.spotify_queue) ? payload.spotify_queue : [];
  const feed = spotifyQueue.length ? spotifyQueue : (Array.isArray(payload.feed) ? payload.feed : []);
  const currentUri = payload.current_track && payload.current_track.uri ? payload.current_track.uri : "";
  return feed.filter((item) => item && item.uri !== currentUri);
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  })[char]);
}

function setBusy(nextBusy) {
  isBusy = nextBusy;
  if (stateEls.toggle) {
    stateEls.toggle.disabled = nextBusy || !isAdmin || !isConfigured;
  }
}

function paintRotor() {
  stateEls.logoRotors.forEach((rotor) => {
    rotor.style.transform = `rotate(${rotorAngle}deg)`;
  });
}

function runRotor(timestamp) {
  if (lastRotorAt === null) {
    lastRotorAt = timestamp;
  }

  const seconds = Math.min(0.08, Math.max(0, (timestamp - lastRotorAt) / 1000));
  lastRotorAt = timestamp;

  const response = rotorTargetVelocity > rotorVelocity ? 4.8 : 1.15;
  const blend = 1 - Math.exp(-response * seconds);
  rotorVelocity += (rotorTargetVelocity - rotorVelocity) * blend;
  rotorAngle = (rotorAngle + rotorVelocity * seconds) % 360;
  if (rotorAngle < 0) {
    rotorAngle += 360;
  }
  paintRotor();

  if (rotorTargetVelocity === 0 && rotorVelocity < 0.03) {
    rotorVelocity = 0;
    lastRotorAt = null;
    rotorFrame = null;
    paintRotor();
    return;
  }

  rotorFrame = window.requestAnimationFrame(runRotor);
}

function setLogoRunning(nextRunning) {
  rotorTargetVelocity = nextRunning ? 32 : 0;
  if (rotorFrame === null && (nextRunning || rotorVelocity > 0)) {
    rotorFrame = window.requestAnimationFrame(runRotor);
  }
}

function scrambleTitle() {
  const title = stateEls.title;
  if (!title || document.hidden) {
    return;
  }

  const original = title.dataset.scrambleTitle || title.textContent || "autoDJ";
  const glyphs = "AUTOdj019#+-*/<>[]";
  const startedAt = performance.now();
  const settleBase = 260;
  const settleStep = 118;
  const duration = settleBase + settleStep * original.length + 180;

  title.classList.add("is-scrambling");

  function frame(now) {
    const elapsed = now - startedAt;
    const nextText = Array.from(original, (char, index) => {
      if (elapsed >= settleBase + index * settleStep) {
        return char;
      }
      const glyphIndex = Math.floor((elapsed / 34) + index * 5) % glyphs.length;
      return glyphs[glyphIndex];
    }).join("");

    title.textContent = nextText;

    if (elapsed < duration) {
      window.requestAnimationFrame(frame);
      return;
    }

    title.textContent = original;
    title.classList.remove("is-scrambling");
  }

  window.requestAnimationFrame(frame);
}

function startTitleScramble() {
  if (!stateEls.title || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    return;
  }

  window.setTimeout(() => {
    scrambleTitle();
    titleScrambleTimer = window.setInterval(scrambleTitle, 5000);
  }, 1200);
}

async function api(path, options = {}) {
  const response = await fetch(`api/${path}`, {
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {})
    },
    ...options
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body.error || `Request failed: ${response.status}`);
  }
  return body;
}

function render(payload) {
  isAdmin = Boolean(payload.admin);
  isConfigured = Boolean(payload.configured);
  isRunning = Boolean(payload.running);

  stateEls.shell?.classList.toggle("is-connected", Boolean(payload.has_tokens));
  stateEls.shell?.classList.toggle("is-running", isRunning);
  setLogoRunning(isRunning);

  if (!stateEls.vibe.value && payload.vibe) {
    stateEls.vibe.value = payload.vibe;
  }

  stateEls.login.textContent = isAdmin ? "Spotify connected" : "Connect Spotify";
  stateEls.login.style.pointerEvents = isAdmin ? "none" : "";
  stateEls.toggleLabel.textContent = isRunning ? "Stop autoDJ" : "Start autoDJ";
  stateEls.toggle?.setAttribute("aria-label", stateEls.toggleLabel.textContent);
  stateEls.toggle?.setAttribute("title", stateEls.toggleLabel.textContent);

  if (!isConfigured) {
    showNotice("Server config is not installed yet. Add local config.php, then refresh.");
  } else if (!payload.has_tokens && isAdmin) {
    showNotice("Spotify is connected for this browser, but no refresh token is stored yet. Reconnect Spotify.");
  } else if (!isAdmin) {
    showNotice("Public view is active. Connect with the bound Spotify account to control the session.");
  } else if (payload.last_error) {
    showNotice(payload.last_error);
  } else {
    showNotice("");
  }

  stateEls.current.innerHTML = trackMarkup(payload.current_track, payload.spotify_playing ? "Playing now" : "Last known");
  const queue = queuedTracks(payload);
  stateEls.feed.innerHTML = queue.length
    ? queue.map((item, index) => `<div class="feed-item">${trackMarkup(item, `Up next ${index + 1}`)}</div>`).join("")
    : '<div class="feed-empty"><span class="empty">Queue appears after autoDJ adds tracks.</span></div>';

  setBusy(isBusy);
  sendHeartbeat();
}

async function refresh() {
  try {
    const payload = await api("status.php");
    render(payload);
  } catch (error) {
    showNotice(error.message);
    setBusy(false);
  }
}

async function sendAction(action) {
  const vibe = stateEls.vibe.value.trim();
  setBusy(true);
  try {
    const payload = await api("action.php", {
      method: "POST",
      body: JSON.stringify({ action, vibe })
    });
    render(payload.status || payload);
    showNotice(payload.message || "");
  } catch (error) {
    showNotice(error.message);
  } finally {
    setBusy(false);
  }
}

function shouldHeartbeat() {
  return isAdmin && isConfigured && isRunning && document.visibilityState !== "hidden";
}

async function sendHeartbeat() {
  if (!shouldHeartbeat() || heartbeatInFlight) {
    return;
  }
  heartbeatInFlight = true;
  try {
    const payload = await api("action.php", {
      method: "POST",
      body: JSON.stringify({ action: "heartbeat" })
    });
    if (payload.status) {
      render(payload.status);
    }
  } catch {
    // The next visible refresh will surface auth or network errors.
  } finally {
    heartbeatInFlight = false;
  }
}

stateEls.form?.addEventListener("submit", (event) => {
  event.preventDefault();
  sendAction(isRunning ? "stop" : "start");
});

refresh();
startTitleScramble();
window.setInterval(refresh, 15000);
window.setInterval(sendHeartbeat, 30000);
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "visible") {
    sendHeartbeat();
    refresh();
  }
});
