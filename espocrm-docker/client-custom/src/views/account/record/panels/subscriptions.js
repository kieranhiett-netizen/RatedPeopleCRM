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

                const newPlanId = window.prompt('Enter new plan ID (applies to whole subscription):');
                if (!newPlanId) {
                    return;
                }

                const useNextBilling = window.confirm('Apply at next billing cycle? (OK = Yes, Cancel = Immediate)');
                const effectivePolicy = useNextBilling ? 'NextBillingPeriod' : 'Immediate';

                this.executeAction('change', {
                    subscriptionIds: subscriptionIds,
                    zuoraSubscriptionIds: zuoraSubscriptionIds,
                    planId: newPlanId,
                    effectivePolicy: effectivePolicy
                });
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

    // Use an Espo modal with explicit buttons instead of browser confirm
    this.createView('cancelDialog', 'views/modal', {
        // options object – we’ll finish setup in the callback
    }, (view) => {
        view.headerText = 'Cancel Subscription';
        view.templateContent = '<p>How would you like to cancel this subscription?</p>';

        view.buttonList = [
            {
                name: 'cancelAtRenewal',
                label: 'Cancel at renewal',
                style: 'default',
                onClick: () => {
                    this.executeAction('cancel', {
                        subscriptionIds: subscriptionIds,
                        zuoraSubscriptionIds: zuoraSubscriptionIds,
                        cancelPolicy: 'EndOfTerm'
                    });
                    view.close();
                },
            },
            {
                name: 'cancelAtNextPayment',
                label: 'Cancel at next payment date',
                style: 'danger',
                onClick: () => {
                    this.executeAction('cancel', {
                        subscriptionIds: subscriptionIds,
                        zuoraSubscriptionIds: zuoraSubscriptionIds,
                        cancelPolicy: 'NextPayment'
                    });
                    view.close();
                },
            },
            {
                name: 'close',
                label: this.translate('Close'),
            },
        ];

        view.render();
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

        // Calls ZuoraSubscription controller – now sending arrays
        executeAction: function (action, payload) {
            const accountId = this.model.id;
            const zuoraAccountId = this.model.get('c_zuoraaccount_id') || null;

            payload.accountId = accountId;
            payload.zuoraAccountId = zuoraAccountId;

            const url = `ZuoraSubscription/action/${action}`;

            Espo.Ajax.postRequest(url, payload)
                .then((response) => {
                    console.log('ZuoraSubscription response:', response);
                    this.notify(response.message || 'Subscription action completed', 'success');

                    // Reload subscriptions so panel reflects changes
                    this.loadSubscriptions();
                })
                .catch((error) => {
                    console.error('Subscription action error:', error);
                    this.notify('Subscription action failed (see console)', 'error');
                });
        }
    });
});
