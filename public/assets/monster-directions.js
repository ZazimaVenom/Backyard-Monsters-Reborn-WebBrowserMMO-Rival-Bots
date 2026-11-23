// Compass bearings are measured in the projected yard, clockwise from screen east.
// An atlas cell names the direction in which the creature actually looks.
const species = 'pokey octo bolt fink eyera ichi bandito fang brain crabatron projectx dave wormzer teratorn zafreeti vorg slimeattikus slimeattikusmini rezghul spurtz zagnoid malphus valgos balthazar grokus sabnox kingwormzer'.split(' ');
const bearings = ['e','se','s','sw','w','nw','n','ne'];
// The six inspected paintings in every atlas share this order. West and
// northwest use reflections of the independently painted east and northeast.
const views = {
  e:{frame:2,flip:false}, se:{frame:3,flip:false}, s:{frame:4,flip:false}, sw:{frame:5,flip:false},
  w:{frame:2,flip:true}, nw:{frame:1,flip:true}, n:{frame:0,flip:false}, ne:{frame:1,flip:false},
};
const kinds = new Set(species);
export const directionSheets = Object.fromEntries(species.map(type=>[
  type+'-directions', {file:type+'-directions.webp',columns:3,rows:2,count:6},
]));
const steps=bearings.map((_,i)=>[Math.cos(i*Math.PI/4),Math.sin(i*Math.PI/4)]);
export function yardBearing(dx, dy) {
  if (!Number.isFinite(dx) || !Number.isFinite(dy) || Math.hypot(dx,dy)<.008) return null;
  const screenX=2*(dx-dy), screenY=dx+dy;
  return bearings[(Math.round(Math.atan2(screenY,screenX)/(Math.PI/4))+8)%8];
}

export function directionCell(type,bearing) {
  return kinds.has(type) ? views[bearing] || null : null;
}

export function directionalFrame(type, action, bearing, images) {
  const key=type+'-directions';
  if (!images[key] || action.evolution || action.key.endsWith('-emerge')) return action;
  // The existing four-pose travel/cast/strike sheets are painted in a front
  // three-quarter view. Preserve that full action when it faces its destination.
  const nativeQuarter=bearing==='se'||bearing==='sw';
  if (nativeQuarter && action.key!==type) return action;
  const cell=directionCell(type,bearing);
  return cell ? {key,frame:cell.frame,flip:cell.flip,directional:true} : action;
}
export function screenStep(bearing) {
  const index=bearings.indexOf(bearing);
  return steps[index] || [0,0];
}
