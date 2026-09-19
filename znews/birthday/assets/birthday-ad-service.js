(() => {
  'use strict';

  class AdService {
    constructor(api) {
      this.api = api;
      this.lastCapability = null;
    }

    async capability(params, draftToken = '') {
      const result = await this.api.ad(params, draftToken);
      this.lastCapability = {
        canReward: result.can_reward === true,
        generationRequiresReward: result.generation_requires_reward === true,
        delivery: result.delivery || { enabled: false }
      };
      return this.lastCapability;
    }

    async canReward(params, draftToken = '') {
      const result = await this.capability(params, draftToken);
      return result.canReward;
    }

    async showRewardedAd(params, draftToken = '') {
      const result = await this.capability(params, draftToken);
      if (!result.canReward) {
        return { completed: false, skipped: true, delivery: result.delivery };
      }
      return { completed: false, skipped: false, delivery: result.delivery };
    }

    handleReward(providerResult) {
      return this.lastCapability?.canReward === true && providerResult?.reward_completed === true;
    }

    async recordAdEvent(slug, provider = 'ADSTERRA') {
      if (!slug) return;
      await this.api.event(slug, 'AD_DELIVERED', { channel: String(provider).toLowerCase() });
    }
  }

  window.BirthdayAdService = new AdService(window.BirthdayApi);
})();
