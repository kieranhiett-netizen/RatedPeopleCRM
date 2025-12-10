define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {
    
    return Dep.extend({
        
        template: 'custom:account/record/panels/subscriptions',

        // Button click events – now call backend stub
        events: {
            'click .action-change-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                // Ask user for a new plan ID (stub input)
                const newPlanId = window.prompt('Enter new plan ID (test only):');
                if (!newPlanId) {
                    return;
                }

                // Ask how to apply the change
                const useNextBilling = window.confirm('Apply at next billing cycle? (OK = Yes, Cancel = Immediate)');
                const effectivePolicy = useNextBilling ? 'NextBillingPeriod' : 'Immediate';

                this.executeAction('change', {
                    subscriptionId: subscriptionId,
                    zuoraSubscriptionId: zuoraSubscriptionId,
                    planId: newPlanId,
                    effectivePolicy: effectivePolicy
                });
            },

            'click .action-cancel-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                const atRenewal = window.confirm('Cancel at renewal? (OK = Yes, Cancel = Next payment date)');
                const cancelPolicy = atRenewal ? 'EndOfTerm' : 'NextPayment';

                if (!window.confirm('Are you sure you want to cancel this subscription?')) {
                    return;
                }

                this.executeAction('cancel', {
                    subscriptionId: subscriptionId,
                    zuoraSubscriptionId: zuoraSubscriptionId,
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
            
            if (!accountId) {
                this.loading = false;
                return;
            }
            
            Espo.Ajax.getRequest(`AccountSubscription/${accountId}`)
                .then(response => {
                    this.loading = false;
                    this.subscriptions = response.subscriptions || [];
                    
                    // Split into active and previous
                    const now = new Date();
                    
                    this.activeSubscriptions = this.subscriptions.filter(sub => {
                        // Active if: status is "Active" OR end_date is in the future/null
                        if (sub.status === 'Active') return true;
                        if (!sub.end_date) return true;
                        
                        const endDate = new Date(sub.end_date);
                        return endDate >= now;
                    });
                    
                    this.previousSubscriptions = this.subscriptions.filter(sub => {
                        // Previous if: status is not "Active" AND end_date is in the past
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

        // NEW: helper to call ZuoraSubscription stub controller
        executeAction: function (action, payload) {
            const accountId = this.model.id;
            const zuoraAccountId = this.model.get('c_zuoraaccount_id') || null; // your Zuora account field

            payload.accountId = accountId;
            payload.zuoraAccountId = zuoraAccountId;

            const url = `ZuoraSubscription/action/${action}`;

            Espo.Ajax.postRequest(url, payload)
                .then((response) => {
                    console.log('ZuoraSubscription response:', response);
                    this.notify(response.message || 'Subscription action completed', 'success');

                    // Reload subscriptions so UI can reflect changes once we start updating DB
                    this.loadSubscriptions();
                })
                .catch((error) => {
                    console.error('Subscription action error:', error);
                    this.notify('Subscription action failed (see console)', 'error');
                });
        }
    });
});
