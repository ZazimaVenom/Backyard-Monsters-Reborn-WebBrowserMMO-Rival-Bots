// Movement profiles for the individually illustrated surface and Inferno monsters.
// The axis follows the mass that flexes (wing span, flame crown, tail, or body).
const profiles = {
  vorg:           { width: 70, axis: 'wing',     speed: 1.65, reach: .100 },
  slimeattikus:   { width: 75, axis: 'gel',      speed: 1.00, reach: .061 },
  slimeattikusmini: { width: 28, axis: 'gel',      speed: 1.55, reach: .085 },
  rezghul:        { width: 65, axis: 'tail',     speed: .90,  reach: .100 },
  spurtz:         { width: 38, axis: 'flame',    speed: 1.85, reach: .067 },
  zagnoid:        { width: 55, axis: 'neck',     speed: .80,  reach: .058 },
  malphus:        { width: 67, axis: 'scuttle',  speed: 1.50, reach: .045 },
  valgos:         { width: 68, axis: 'scuttle',  speed: .88,  reach: .047 },
  balthazar:      { width: 73, axis: 'wing',     speed: 1.30, reach: .115 },
  grokus:         { width: 69, axis: 'tentacle', speed: .85,  reach: .079 },
  sabnox:         { width: 73, axis: 'neck',     speed: .78,  reach: .063 },
  kingwormzer:    { width: 91, axis: 'neck',     speed: .62,  reach: .037 },
};
const originalProfiles = {
  pokey: {axis:'scuttle',speed:1.25,reach:.045}, octo: {axis:'tentacle',speed:1.2,reach:.07},
  bolt: {axis:'neck',speed:1.55,reach:.058}, fink: {axis:'scuttle',speed:1.45,reach:.048},
  eyera: {axis:'gel',speed:1.02,reach:.052}, ichi: {axis:'scuttle',speed:.92,reach:.046},
  bandito: {axis:'neck',speed:1.32,reach:.048}, fang: {axis:'scuttle',speed:1.25,reach:.050},
  brain: {axis:'scuttle',speed:1.22,reach:.038}, crabatron: {axis:'scuttle',speed:1.1,reach:.045},
  projectx: {axis:'tentacle',speed:1.03,reach:.073}, dave: {axis:'scuttle',speed:.85,reach:.012},
  wormzer: {axis:'neck',speed:.95,reach:.061}, teratorn: {axis:'wing',speed:1.90,reach:.10},
  zafreeti: {axis:'tail',speed:.82,reach:.08},
};
export const creatureWidths = Object.fromEntries(Object.entries(profiles).map(([id, profile]) => [id, profile.width]));
const tau = 2 * Math.PI, phases = 8;

export function creaturePose(type, seconds, seed, moving, directional = false) {
  const profile = profiles[type] || (directional && originalProfiles[type]);
  if (!profile) return null;
  const speed = profile.speed * (moving ? 1 : .60);
  return Math.floor((seconds * speed + seed * .381966) * phases) % phases;
}

// Each strip is one integer destination pixel. This leaves no antialiased seams,
// even on high-DPI battlefields. Frames are built at first use and reused by
// every keeper's copy of that species.
export function makeCreatureFrame(type, phase, image, bounds, resolution = 384) {
  const profile = profiles[type] || originalProfiles[type];
  if (!profile) return null;
  const canvas = document.createElement('canvas');
  const scale = Math.min(resolution / bounds.w, resolution / bounds.h, 1);
  const width = Math.max(1, Math.round(bounds.w * scale)), height = Math.max(1, Math.round(bounds.h * scale));
  const margin = Math.ceil(Math.max(width, height) * .13) + 2;
  canvas.width = width + 2 * margin; canvas.height = height + 2 * margin;
  const ctx = canvas.getContext('2d'), a = tau * phase / phases;
  const swing = Math.sin(a), axis = profile.axis, reach = profile.reach;
  const vertical = axis === 'wing' || axis === 'scuttle';
  const steps = vertical ? width : height;
  for (let i = 0; i < steps; i++) {
    const u = (i + .5) / steps, edge = Math.abs(2 * u - 1);
    if (vertical) {
      // The distal wing lags the shoulder during both the upstroke and downstroke.
      const lift = axis === 'wing'
        ? -Math.sin(a - edge * 1.6) * edge * edge * width * reach
        : Math.sin(a + tau * u) * edge * height * reach;
      ctx.drawImage(image, bounds.x + i * bounds.w / width, bounds.y, bounds.w / width, bounds.h,
        margin + i, margin + lift, 1, height);
    } else {
      let shift = 0, stretch = 0;
      if (axis === 'gel') {
        stretch = Math.sin(a + Math.PI * u) * Math.sin(Math.PI * u) * reach;
        shift = swing * Math.sin(Math.PI * u) * .018;
      } else if (axis === 'flame') {
        shift = Math.sin(a + u * 3) * (1 - u) ** 2 * reach;
      } else if (axis === 'tail' || axis === 'tentacle') {
        shift = Math.sin(a + 3 * u) * u ** 2 * reach;
        if (axis === 'tentacle') stretch = Math.cos(a + u * 2) * u ** 2 * .030;
      } else {
        shift = Math.sin(a + 1.5 * u) * (1 - u) ** 2 * reach;
      }
      ctx.drawImage(image, bounds.x, bounds.y + i * bounds.h / height, bounds.w, bounds.h / height,
        margin + (shift - stretch / 2) * width, margin + i, width * (1 + stretch), 1);
    }
  }
  return {canvas, width, height, margin};
}

export function drawCreatureAbility(context, event, origin, target, ground, age, reduced) {
  const type = event.type;
  if (type !== 'split' && type !== 'resurrect' && type !== 'scorch') return false;
  const life = type === 'scorch' ? 1.55 : .9;
  if (age >= life) return true;
  const fraction = reduced ? .55 : Math.min(1, age / life);
  context.globalAlpha = Math.min(1, (1 - age / life) * 1.9);
  if (type === 'scorch') {
    context.fillStyle = '#4c382fc7';
    context.beginPath(); context.ellipse(ground.x, ground.y + 1, 15, 5.5, -.16, 0, tau); context.fill();
    for (let i = 0; i < (reduced ? 2 : 6); i++) {
      const angle = event.id + i * 2.4, distance = 3 + fraction * 10;
      context.fillStyle = i % 2 ? '#ffba66' : '#eb7849'; context.beginPath();
      context.arc(ground.x + Math.cos(angle) * distance, ground.y + Math.sin(angle) * distance * .35 - fraction * (9 + 2 * i), 1.7, 0, tau); context.fill();
    }
    return true;
  }
  if (type === 'resurrect') {
    context.strokeStyle = '#79dfd4'; context.lineWidth = 1.8;
    context.beginPath(); context.moveTo(origin.x, origin.y);
    context.quadraticCurveTo((origin.x + target.x) / 2, Math.min(origin.y, target.y) - 20, target.x, target.y); context.stroke();
    for (let i = 0; i < 2; i++) {
      context.strokeStyle = i ? '#effae5' : '#54bbbd';
      context.beginPath(); context.ellipse(target.x, target.y + 8 - 16 * fraction - i * 5,
        10 + fraction * 6, 3.5 + fraction * 2, 0, fraction * tau + i, fraction * tau + i + Math.PI * 1.55); context.stroke();
    }
    return true;
  }
  const dx = target.x - ground.x, dy = target.y - ground.y;
  for (let i = 0; i < (reduced ? 2 : 5); i++) {
    const t = Math.max(0, Math.min(1, fraction * 1.45 - i * .09));
    const lift = (10 + i * 3) * Math.sin(t * Math.PI);
    context.fillStyle = i % 2 ? '#9cc244' : '#d1ee78'; context.beginPath();
    context.ellipse(ground.x + dx * t + (i - 2) * Math.sin(t * Math.PI) * 2,
      ground.y + dy * t - lift, 2.5 + .5 * (i % 2), 2 + (1 - t), -.6, 0, tau); context.fill();
  }
  return true;
}
