define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {

    return Dep.extend({

        template: 'custom:account/record/panels/subscriptions',

        events: {
            'click .action-change-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                this.openChangeModal({
                    subscriptionId: subscriptionId,
                    zuoraSubscriptionId: zuoraSubscriptionId
                });
            },

            'click .action-cancel-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                this.openCancelModal({
                    subscriptionId: subscriptionId,
                    zuoraSubscriptionId: zuoraSubscriptionId
                });
            }
        },

        data: function () {
            return {
                subscriptions: this.subscriptions || [],
                activeSubscriptions: this.activeSubscriptions || [],
                previousSubscriptions: this.previousSubscriptions || [],
                loading: this.loading,
                error: this.error
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.loading = true;
            this.subscriptions = [];
            this.activeSubscriptions = [];
            this.previousSubscriptions = [];
            this.error = null;

            this.loadSubscriptions();
        },

        loadSubscriptions: function () {
            const accountId = this.model.id;

            if (!accountId) {
                this.loading = false;
                return;
            }

            Espo.Ajax.getRequest(`AccountSubscription/${accountId}`)
                .then(response => {
                    this.loading = false;
                    this.subscriptions = response.subscriptions || [];

                    const now = new Date();

                    this.activeSubscriptions = this.subscriptions.filter(sub => {
                        if (sub.status === 'Active') return true;
                        if (!sub.end_date) return true;

                        const endDate = new Date(sub.end_date);
                        return endDate >= now;
                    });

                    this.previousSubscriptions = this.subscriptions.filter(sub => {
                        if (sub.status === 'Active') return false;
                        if (!sub.end_date) return false;

                        const endDate = new Date(sub.end_date);
                        return endDate < now;
                    });

                    this.reRender();
                })
                .catch(error => {
                    this.loading = false;
                    this.error = 'Failed to load subscriptions';
                    console.error('Subscription load error:', error);
                    this.reRender();
                });
        },

        // --- NEW: open modals / execute API calls ---

        openChangeModal: function (ctx) {
            // You can later swap this for a full modal; for now keep it simple:
            const newPlanId = prompt('Enter new plan ID:');
            if (!newPlanId) return;

            const effective = window.confirm('Apply at next billing period?')
                ? 'NextBillingPeriod'
                : 'Immediate';

            this.executeAction('change', {
                subscriptionId: ctx.subscriptionId,
                zuoraSubscriptionId: ctx.zuoraSubscriptionId,
                planId: newPlanId,
                effectivePolicy: effective
            });
        },

        openCancelModal: function (ctx) {
            const choice = window.confirm('Cancel at next renewal (OK) or next payment date (Cancel)?');
            const cancelPolicy = choice ? 'EndOfTerm' : 'NextPayment';

            if (!window.confirm('Are you sure you want to cancel this subscription?')) {
                return;
            }

            this.executeAction('cancel', {
                subscriptionId: ctx.subscriptionId,
                zuoraSubscriptionId: ctx.zuoraSubscriptionId,
                cancelPolicy: cancelPolicy
            });
        },

        executeAction: function (type, payload) {
            const accountId = this.model.id;
            const zuoraAccountId = this.model.get('c_zuoraaccount_id') || null; // <— YOUR FIELD

            const url = `ZuoraSubscription/action/${type}`;

            payload.accountId = accountId;
            payload.zuoraAccountId = zuoraAccountId;

            Espo.Ajax.postRequest(url, payload)
                .then(response => {
                    this.notify('Subscription updated', 'success');

                    // Re-use your existing loader so we always refresh from DB
                    this.loadSubscriptions();
                })
                .catch(err => {
                    console.error('Subscription action error:', err);
                    this.notify('Failed to update subscription', 'error');
                });
        }
    });
});
