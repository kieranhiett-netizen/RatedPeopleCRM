define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {

    return Dep.extend({

        template: 'custom:account/record/panels/subscriptions',

        // Panel-level actions
        events: {
            // Refresh
            'click .action-refresh-subscription-panel': function (e) {
                e.preventDefault();
                this.loadSubscriptions();
            },

            // CHANGE PLAN actions
            'click .action-change-subscription-now': function (e) {
                e.preventDefault();

                if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                    this.notify('No active subscriptions to change.', 'warning');
                    return;
                }

                const subscriptionIds = this.activeSubscriptions.map(s => s.id);
                const zuoraSubscriptionIds = this.activeSubscriptions
                    .map(s => s.zuora_subscription_id)
                    .filter(Boolean);

                // Effective immediately
                this.openPlanSelectDialog(
                    subscriptionIds,
                    zuoraSubscriptionIds,
                    'Immediate'
                );
            },

            'click .action-change-subscription-at-payment': function (e) {
                e.preventDefault();

                if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                    this.notify('No active subscriptions to change.', 'warning');
                    return;
                }

                const subscriptionIds = this.activeSubscriptions.map(s => s.id);
                const zuoraSubscriptionIds = this.activeSubscriptions
                    .map(s => s.zuora_subscription_id)
                    .filter(Boolean);

                // Change at next billing period / payment
                this.openPlanSelectDialog(
                    subscriptionIds,
                    zuoraSubscriptionIds,
                    'NextBillingPeriod'
                );
            },

            'click .action-change-subscription-at-renewal': function (e) {
                e.preventDefault();

                if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                    this.notify('No active subscriptions to change.', 'warning');
                    return;
                }

                const subscriptionIds = this.activeSubscriptions.map(s => s.id);
                const zuoraSubscriptionIds = this.activeSubscriptions
                    .map(s => s.zuora_subscription_id)
                    .filter(Boolean);

                // Change at renewal / end of term
                this.openPlanSelectDialog(
                    subscriptionIds,
                    zuoraSubscriptionIds,
                    'EndOfTerm'
                );
            },

            // CANCEL actions
            'click .action-cancel-subscription-immediate': function (e) {
                e.preventDefault();
                this.cancelSubscriptions(
                    'Immediate',
                    'Are you sure you want to cancel this subscription immediately?'
                );
            },

            'click .action-cancel-subscription-next-payment': function (e) {
                e.preventDefault();
                this.cancelSubscriptions(
                    'NextPayment',
                    'Are you sure you want to cancel this subscription at the next payment date?'
                );
            },

            'click .action-cancel-subscription-next-renewal': function (e) {
                e.preventDefault();
                this.cancelSubscriptions(
                    'EndOfTerm',
                    'Are you sure you want to cancel this subscription at the next renewal?'
                );
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

            // Auto-refresh when Zuora Account ID changes on the Account
            this.listenTo(this.model, 'change:cZuoraAccountId', function () {
                this.loadSubscriptions();
            }, this);

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

            const payload = {};

            if (zuoraAccountId) {
                payload.zuoraAccountId = zuoraAccountId;
            }

            if (accountId) {
                payload.accountId = accountId;
            }

            Espo.Ajax.postRequest('ZuoraSubscription/action/list', payload)
                .then(response => {
                    console.log('ZuoraSubscription list response:', response);

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
                    console.error('Zuora subscription fetch failed:', error);
                    this.loading = false;
                    this.error = 'Failed to load subscriptions from Zuora';
                    this.reRender();
                });
        },

        /**
         * Open plan selector for a given effectivePolicy.
         *
         * @param {Array} subscriptionIds
         * @param {Array} zuoraSubscriptionIds
         * @param {String} effectivePolicy  e.g. 'Immediate', 'NextBillingPeriod', 'EndOfTerm'
         */
        openPlanSelectDialog: function (subscriptionIds, zuoraSubscriptionIds, effectivePolicy) {
            const scope = 'CSubscriptionPlan';
            const policy = effectivePolicy || 'Immediate';

            this.createView('selectPlan', 'views/modals/select-records', {
                scope: scope,
                multiple: false
            }, function (view) {
                view.render();

                // Fired when user selects a plan from the list
                this.listenToOnce(view, 'select', function (model) {
                    const planId = model.id;
                    const planCode = model.get('planCode') || model.get('plan_code') || null;

                    let summary = 'Are you sure you want to change the subscription';
                    summary += '\n\nPlan: ' + (planCode || planId);
                    summary += '\nPolicy: ' + policy;

                    if (!window.confirm(summary)) {
                        return;
                    }

                    this.executeAction('change', {
                        subscriptionIds: subscriptionIds,
                        zuoraSubscriptionIds: zuoraSubscriptionIds,
                        planId: planId,
                        planCode: planCode,
                        effectivePolicy: policy
                    });
                }, this);
            }.bind(this));
        },

        /**
         * Helper to perform a cancel with a given policy.
         *
         * @param {String} cancelPolicy   'Immediate', 'NextPayment', 'EndOfTerm'
         * @param {String} confirmMessage Message for confirm dialog
         */
        cancelSubscriptions: function (cancelPolicy, confirmMessage) {
            if (!this.activeSubscriptions || !this.activeSubscriptions.length) {
                this.notify('No active subscriptions to cancel.', 'warning');
                return;
            }

            const subscriptionIds = this.activeSubscriptions.map(s => s.id);
            const zuoraSubscriptionIds = this.activeSubscriptions
                .map(s => s.zuora_subscription_id)
                .filter(Boolean);

            if (!window.confirm(confirmMessage)) {
                return;
            }

            this.executeAction('cancel', {
                subscriptionIds: subscriptionIds,
                zuoraSubscriptionIds: zuoraSubscriptionIds,
                cancelPolicy: cancelPolicy
            });
        },

        // Calls ZuoraSubscription controller
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
