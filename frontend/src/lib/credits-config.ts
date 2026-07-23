/**
 * Credits cost configuration
 * Update these values based on the actual backend credits cost configuration
 */

export const CREDITS_COST = {
  // Cost per thumbnail generation
  CREATE_THUMBNAIL: 25,
  
  // Cost per script generation
  SCRIPT: 50,
  
  // Cost per title generation
  TITLE: 10,
} as const;

/**
 * Calculate how many items can be generated with given credits
 */
export function calculateUsageFromCredits(totalCredits: number) {
  return {
    thumbnails: Math.floor(totalCredits / CREDITS_COST.CREATE_THUMBNAIL),
    scripts: Math.floor(totalCredits / CREDITS_COST.SCRIPT),
    titles: Math.floor(totalCredits / CREDITS_COST.TITLE),
  };
}



