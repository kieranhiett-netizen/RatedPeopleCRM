define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {

    return Dep.extend({

        template: 'custom:account/record/panels/subscriptions',

        // Panel-level actions: one Change + one Cancel for all active subs
        events: {
            'click .action-change-subscription-panel': function (e) {
                if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                    this.notify('No active subscriptions to change.', 'warning');
                    return;
                }

                const subscriptionIds = this.activeSubscriptions.map(s => s.id);
                const zuoraSubscriptionIds = this.activeSubscriptions
                    .map(s => s.zuora_subscription_id)
                    .filter(Boolean);

                this.openPlanSelectDialog(subscriptionIds, zuoraSubscriptionIds);
            },

            'click .action-cancel-subscription-panel': function (e) {
                if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                    this.notify('No active subscriptions to cancel.', 'warning');
                    return;
                }

                const subscriptionIds = this.activeSubscriptions.map(s => s.id);
                const zuoraSubscriptionIds = this.activeSubscriptions
                    .map(s => s.zuora_subscription_id)
                    .filter(Boolean);

                const atRenewal = window.confirm(
                    'Cancel at renewal?\n\nOK = cancel at renewal\nCancel = cancel at next payment date'
                );
                const cancelPolicy = atRenewal ? 'EndOfTerm' : 'NextPayment';

                if (!window.confirm('Are you sure you want to cancel the whole subscription?')) {
                    return;
                }

                this.executeAction('cancel', {
                    subscriptionIds: subscriptionIds,
                    zuoraSubscriptionIds: zuoraSubscriptionIds,
                    cancelPolicy: cancelPolicy
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
            const zuoraAccountId = this.model.get('cZuoraAccountId') || null;

            if (!accountId && !zuoraAccountId) {
                this.loading = false;
                this.error = 'No Account or Zuora Account ID.';
                this.reRender();
                return;
            }

            this.loading = true;
            this.error = null;
            this.reRender();

            Espo.Ajax.postRequest('ZuoraSubscription/action/list', {
                accountId: accountId,
                zuoraAccountId: zuoraAccountId
            })
                .then(response => {
                    console.log('ZuoraSubscription list response:', response);

                    this.loading = false;

                    // use what the controller returns
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
                    console.error('Zuora subscription fetch failed:', error);
                    this.loading = false;
                    this.error = 'Failed to load subscriptions from Zuora';
                    this.reRender();
                });
        },

        openPlanSelectDialog: function (subscriptionIds, zuoraSubscriptionIds) {
            const scope = 'CSubscriptionPlan';

            // NOTE: correct view path is select-record (singular)
            this.createView('selectPlan', 'views/modals/select-record', {
                scope: scope,
                multiple: false
            }, function (view) {
                view.render();

                // Fired when user selects a plan from the list
                this.listenToOnce(view, 'select', function (model) {
                    const planId = model.id;
                    const planCode = model.get('planCode') || model.get('plan_code') || null;

                    const useNextBilling = window.confirm(
                        'Apply plan change at next billing cycle?\n\nOK = Next billing\nCancel = Immediate'
                    );
                    const effectivePolicy = useNextBilling ? 'NextBillingPeriod' : 'Immediate';

                    this.executeAction('change', {
                        subscriptionIds: subscriptionIds,
                        zuoraSubscriptionIds: zuoraSubscriptionIds,
                        planId: planId,
                        planCode: planCode,
                        effectivePolicy: effectivePolicy
                    });
                }, this);
            }.bind(this));
        },

        // Calls ZuoraSubscription controller – now sending arrays
        executeAction: function (action, payload) {
            const accountId = this.model.id;
            const zuoraAccountId = this.model.get('cZuoraAccountId') || null;

            payload.accountId = accountId;
            payload.zuoraAccountId = zuoraAccountId;

            const url = 'ZuoraSubscription/action/' + action;

            Espo.Ajax.postRequest(url, payload)
                .then(response => {
                    console.log('ZuoraSubscription response:', response);
                    this.notify(response.message || 'Subscription action completed', 'success');

                    // Reload subscriptions so panel reflects changes
                    this.loadSubscriptions();
                })
                .catch(error => {
                    console.error('Subscription action error:', error);
                    this.notify('Subscription action failed (see console)', 'error');
                });
        }
    });
});
