define('custom:views/account/record/panels/payments', ['views/record/panels/bottom'], function (Dep) {

    return Dep.extend({

        template: 'custom:account/record/panels/payments',

        events: {
            'click .action-refresh-payment-panel': function (e) {
                e.preventDefault();
                this.loadPayments();
            }
        },

        data: function () {
            return {
                payments: this.payments || [],
                loading: this.loading,
                error: this.error
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.loading = true;
            this.error = null;
            this.payments = [];

            // Refresh when Zuora Account ID changes
            this.listenTo(this.model, 'change:cZuoraAccountId', function () {
                this.loadPayments();
            }, this);

            this.loadPayments();
        },

        loadPayments: function () {
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

            const payload = {
                accountId: accountId,
                zuoraAccountId: zuoraAccountId,

                  // ✅ TEMP DEBUG FLAG (remove after testing)
    debug: true
            };

            Espo.Ajax.postRequest('ZuoraPayment/action/list', payload)
                .then(response => {
                    this.loading = false;
                    this.payments = (response && response.payments) ? response.payments : [];

                    // Sort newest first (dateIso)
                    this.payments.sort((a, b) => {
                        const da = a.dateIso ? new Date(a.dateIso) : new Date(0);
                        const db = b.dateIso ? new Date(b.dateIso) : new Date(0);
                        return db - da;
                    });

                    this.reRender();
                })
                .catch(error => {
                    console.error('Zuora payments fetch failed:', error);
                    this.loading = false;
                    this.error = 'Failed to load payments from Zuora';
                    this.reRender();
                });
        },

        /**
         * Format ISO date as DD-Mmm-YYYY like your screenshot.
         */
        formatDisplayDate: function (iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return iso;

            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sept','Oct','Nov','Dec'];
            const dd = String(d.getDate()).padStart(2, '0');
            const m = months[d.getMonth()] || '';
            const yyyy = d.getFullYear();
            return `${dd}-${m}-${yyyy}`;
        }

    });
});
