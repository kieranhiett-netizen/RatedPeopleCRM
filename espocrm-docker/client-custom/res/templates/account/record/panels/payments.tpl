{{!-- Action buttons --}}
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
                        <th style="width: 14%;">Payment</th>
                        <th style="width: 18%;">Cardholder</th>
                        <th style="width: 10%;">Amount</th>
                        <th style="width: 14%;">Gateway</th>
                        <th style="width: 12%;">Status</th>
                        <th style="width: 14%;">Date</th>
                        <th style="width: 10%;">Method</th>
                        <th style="width: 8%;">Exp</th>
                    </tr>
                </thead>

                <tbody>
                    {{#each payments}}
                        <tr>
                            <td>{{payment}}</td>
                            <td>{{cardholder}}</td>
                            <td>{{displayAmount}}</td>
                            <td>{{gateway}}</td>

                            <td>
                                {{#if statusText}}
                                    <span class="{{statusClass}}">{{statusText}}</span>
                                {{else}}
                                    <span class="label label-default">-</span>
                                {{/if}}
                            </td>

                            <td>{{displayDate}}</td>
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
