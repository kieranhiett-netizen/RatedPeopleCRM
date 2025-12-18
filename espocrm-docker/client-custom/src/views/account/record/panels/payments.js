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
                // IMPORTANT: do not send debug:true unless your controller returns payments in debug mode
            };

            console.log('[ZuoraPayment] payload', payload);

            Espo.Ajax.postRequest('ZuoraPayment/action/list', payload)
                .then(response => {
                    console.log('[ZuoraPayment] response', response);

                    this.loading = false;

                    const rawPayments = (response && Array.isArray(response.payments)) ? response.payments : [];

                    // Normalize & add display-only fields so the template stays dumb (no eq helper needed)
                    this.payments = rawPayments.map(p => {
                        const statusRaw = (p && p.status) ? String(p.status) : '';
                        const status = statusRaw.toLowerCase();

                        let statusClass = 'label label-default';
                        if (status === 'processed' || status === 'success') {
                            statusClass = 'label label-success';
                        } else if (status === 'error' || status === 'failed' || status === 'canceled' || status === 'cancelled') {
                            statusClass = 'label label-danger';
                        } else if (status === 'pending' || status === 'processing') {
                            statusClass = 'label label-warning';
                        }

                        const amount = (p && p.amount !== null && p.amount !== undefined) ? p.amount : null;

                        return Object.assign({}, p, {
                            // template-friendly display fields
                            displayDate: this.formatDisplayDate(p.dateIso),
                            displayAmount: this.formatAmount(amount),
                            statusText: statusRaw,
                            statusClass: statusClass,

                            // make sure these exist so template doesn’t blow up
                            payment: p.payment || '',
                            cardholder: p.cardholder || '',
                            gateway: p.gateway || '',
                            method: p.method || '',
                            expiration: p.expiration || ''
                        });
                    });

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
         * Format ISO date as DD-Mmm-YYYY (e.g. 20-Sep-2022)
         */
        formatDisplayDate: function (iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return String(iso);

            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const dd = String(d.getDate()).padStart(2, '0');
            const m = months[d.getMonth()] || '';
            const yyyy = d.getFullYear();
            return `${dd}-${m}-${yyyy}`;
        },

        /**
         * Basic amount formatting. Keeps your value but renders consistently.
         * If you later want currency symbols, we can add them once you confirm the currency.
         */
        formatAmount: function (amount) {
            if (amount === null || amount === undefined || amount === '') return '';
            const n = Number(amount);
            if (isNaN(n)) return String(amount);

            // If it's an integer, show without decimals; else 2dp
            if (Math.round(n) === n) return String(n);
            return n.toFixed(2);
        }

    });
});
