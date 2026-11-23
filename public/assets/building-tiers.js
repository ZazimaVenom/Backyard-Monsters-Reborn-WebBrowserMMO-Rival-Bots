// A working building keeps its completed architecture until its paid upgrade finishes.
export const buildingTierLimits = {
  surface: {"townhall": 10, "hatchery": 5, "housing": 5, "twig": 5, "pebble": 5, "putty": 5, "goo": 5, "storage": 5, "sniper": 5, "cannon": 5, "tesla": 5, "wall": 5, "academy": 5, "baiter": 7, "flinger": 5, "locker": 5, "juicer": 5, "laser": 5, "aerial": 5, "bunker": 5, "trap": 5, "lab": 3, "catapult": 4, "championcage": 5, "championchamber": 5, "maproom": 3, "outpost": 5, "stronghold": 3, "outpostdefender": 3, "siegelab": 10, "railgun": 8, "quaketower": 6, "infernocannon": 7, "magmatower": 6, "spurtzcannon": 5, "diamondspurtz": 5},
  inferno: {"townhall": 6, "twig": 5, "pebble": 5, "putty": 5, "goo": 5, "storage": 5, "hatchery": 5, "housing": 6, "flinger": 5, "wall": 5, "sniper": 5, "locker": 5, "academy": 5, "infernocannon": 7, "quaketower": 6, "magmatower": 6},
};

export function buildingTierKey(building, underground) {
  if (!building) return null;
  const realm = underground ? 'inferno' : 'surface';
  const level = Number(building.level);
  return Number.isInteger(level) && level >= 2 && level <= (buildingTierLimits[realm][building.type] || 0)
    ? realm + '-' + building.type + '-' + level : null;
}
