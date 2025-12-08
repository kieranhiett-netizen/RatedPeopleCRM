define('custom:views/account/record/panels/case-history', ['views/record/panels/bottom'], function (Dep) {

    return Dep.extend({

        template: 'custom:account/record/panels/case-history',

        data: function () {
            return {
                cases: this.cases || [],
                loading: this.loading,
                error: this.error,
                message: this.message,
                caseCount: this.caseCount || 0,
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.loading = true;
            this.cases = [];
            this.error = null;
            this.message = null;
            this.caseCount = 0;

            this.loadCaseHistory();
        },

        loadCaseHistory: function () {
            const accountId = this.model.id;

            if (!accountId) {
                this.loading = false;
                this.error = 'No account ID.';
                this.reRender();
                return;
            }

            Espo.Ajax.getRequest(`AccountCaseHistory/${accountId}`)
                .then(response => {
                    this.loading = false;

                    if (response.error) {
                        this.error = response.error;
                        this.message = response.message || null;
                        this.reRender();
                        return;
                    }

                    this.caseCount = response.caseCount || 0;

                    this.cases = (response.cases || []).map(item => {
                        // Simple label colour for status
                        let statusLabelClass = 'label-default';

                        if (item.status === 'Resolved' || item.status === 'Closed') {
                            statusLabelClass = 'label-success';
                        } else if (item.status === 'Open' || item.status === 'In Progress') {
                            statusLabelClass = 'label-primary';
                        }

                        item.statusLabelClass = statusLabelClass;

                        return item;
                    });

                    this.message = response.message || null;

                    this.reRender();
                })
                .catch(error => {
                    this.loading = false;
                    this.error = 'Failed to load case history';
                    console.error('Case history load error:', error);
                    this.reRender();
                });
        },
    });
});
