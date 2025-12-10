define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {
    
    return Dep.extend({
        
        template: 'custom:account/record/panels/subscriptions',

        // NEW: button click events
        events: {
            'click .action-change-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                console.log('Change subscription clicked', {
                    subscriptionId,
                    zuoraSubscriptionId
                });

                this.notify('Change subscription clicked (not wired yet)', 'info');
            },

            'click .action-cancel-subscription': function (e) {
                const $btn = $(e.currentTarget);
                const subscriptionId = $btn.data('id');
                const zuoraSubscriptionId = $btn.data('zuora-subscription-id');

                console.log('Cancel subscription clicked', {
                    subscriptionId,
                    zuoraSubscriptionId
                });

                this.notify('Cancel subscription clicked (not wired yet)', 'info');
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
        }
    });
});
