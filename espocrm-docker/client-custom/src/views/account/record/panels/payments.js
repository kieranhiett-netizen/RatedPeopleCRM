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
                zuoraAccountId: zuoraAccountId
                // NOTE: do NOT send debug:true unless your controller returns payments in debug mode
            };

            console.log('[ZuoraPayment] payload', payload);

            Espo.Ajax.postRequest('ZuoraPayment/action/list', payload)
                .then(response => {
                    console.log('[ZuoraPayment] response', response);

                    this.loading = false;
                    this.payments = (response && Array.isArray(response.payments)) ? response.payments : [];

                    // Sort newest first (dateIso)
                    this.payments.sort((a, b) => {
                        const da = a && a.dateIso ? new Date(a.dateIso) : new Date(0);
                        const db = b && b.dateIso ? new Date(b.dateIso) : new Date(0);
                        return db - da;
                    });

                    this.reRender();
                })
                .catch(error => {
                    console.error('[ZuoraPayment] error', error);

                    this.loading = false;
                    this.error = 'Failed to load payments from Zuora';
                    this.reRender();
                });
        },

        /**
         * Format ISO date as DD-Mmm-YYYY like the screenshot.
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
