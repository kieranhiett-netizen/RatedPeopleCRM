<div class="pull-right" style="margin-bottom: 10px; text-align: right;">
    <button
        type="button"
        class="btn btn-default btn-sm action-refresh-payment-panel"
        {{#if loading}}disabled{{/if}}>
        Refresh
    </button>
</div>

<div class="clearfix" style="margin-bottom: 10px;"></div>

{{#if loading}}
    <div class="text-muted">Loading payments...</div>
{{/if}}

{{#if error}}
    <div class="text-danger">{{error}}</div>
{{/if}}

{{#unless loading}}
    {{#unless error}}
        {{#if payments.length}}
            <table class="table table-panel">
                <thead>
                    <tr>
                        <th style="width: 12%;">Payment</th>
                        <th style="width: 16%;">Cardholder</th>
                        <th style="width: 10%;">Amount</th>
                        <th style="width: 18%;">Gateway</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 16%;">Method</th>
                        <th style="width: 6%;">Expiration</th>
                    </tr>
                </thead>
                <tbody>
                    {{#each payments}}
                        <tr>
                            <td>{{payment}}</td>
                            <td>{{cardholder}}</td>
                            <td>{{amount}}</td>
                            <td title="{{gateway}}">{{gateway}}</td>
                            <td>
                                {{#if (eq status "Processed")}}
                                    <span class="label label-success">{{status}}</span>
                                {{else}}
                                    <span class="label label-default">{{status}}</span>
                                {{/if}}
                            </td>
                            <td>{{dateIso}}</td>
                            <td>{{method}}</td>
                            <td>{{expiration}}</td>
                        </tr>
                    {{/each}}
                </tbody>
            </table>
        {{else}}
            <div class="text-muted">No payments found for this account.</div>
        {{/if}}
    {{/unless}}
{{/unless}}
