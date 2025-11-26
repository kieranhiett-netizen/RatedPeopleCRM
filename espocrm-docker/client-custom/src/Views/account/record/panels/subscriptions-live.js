define('custom:views/account/record/panels/subscriptions-live', ['views/record/panel'], function (Dep) {

    return Dep.extend({

        name: 'subscriptions-live',
        template: 'custom:account.subscriptions-live',

        data: function () {
            return {
                loading: this.loading,
                error: this.error,
                rows: this.rows || []
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.loading = true;
            this.error = null;
            this.rows = [];

            this.loadData();
        },

        loadData: function () {
            this.loading = true;
            this.error = null;

            const accountId = this.getParentView().model.id;
            const url = 'Account/action/getSubscriptions?id=' + accountId;

            this.ajaxGetRequest(url).then((response) => {
                if (response.success) {
                    this.rows = response.data;
                } else {
                    this.error = response.message || 'Failed to load data.';
                }

                this.loading = false;
                this.reRender();

            }).catch(() => {
                this.error = 'Error contacting server.';
                this.loading = false;
                this.reRender();
            });
        },

        actionRefreshSubscriptions: function () {
            this.loadData();
        }
    });
});
