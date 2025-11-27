define('custom:views/account/record/panels/subscriptions', ['views/record/panels/bottom'], function (Dep) {
    
    return Dep.extend({
        
        template: 'custom:account/record/panels/subscriptions',
        
        data: function () {
            return {
                subscriptions: this.subscriptions || [],
                loading: this.loading,
                error: this.error
            };
        },
        
        setup: function () {
            Dep.prototype.setup.call(this);
            
            this.loading = true;
            this.subscriptions = [];
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