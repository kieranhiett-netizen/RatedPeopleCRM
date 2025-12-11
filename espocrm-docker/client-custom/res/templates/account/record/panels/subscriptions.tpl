{{!-- Action buttons --}}
<div class="pull-right" style="margin-bottom: 10px; text-align: right;">

    <button
        type="button"
        class="btn btn-default btn-sm action-refresh-subscription-panel"
        {{#if loading}}disabled{{/if}}>
        Refresh
    </button>

    {{#if activeSubscriptions.length}}
        <div style="margin-top: 5px;">
            <strong>Change plan:</strong>
            <button
                type="button"
                class="btn btn-primary btn-xs action-change-subscription-now">
                Now
            </button>
            <button
                type="button"
                class="btn btn-primary btn-xs action-change-subscription-at-payment">
                At next payment
            </button>
            <button
                type="button"
                class="btn btn-primary btn-xs action-change-subscription-at-renewal">
                At renewal
            </button>
        </div>

        <div style="margin-top: 5px;">
            <strong>Cancel subscription:</strong>
            <button
                type="button"
                class="btn btn-danger btn-xs action-cancel-subscription-immediate">
                Immediately
            </button>
            <button
                type="button"
                class="btn btn-danger btn-xs action-cancel-subscription-next-payment">
                At next payment
            </button>
            <button
                type="button"
                class="btn btn-danger btn-xs action-cancel-subscription-next-renewal">
                At renewal
            </button>
        </div>
    {{else}}
        <div style="margin-top: 5px;">
            <strong>Change plan:</strong>
            <button type="button" class="btn btn-primary btn-xs" disabled>Now</button>
            <button type="button" class="btn btn-primary btn-xs" disabled>At next payment</button>
            <button type="button" class="btn btn-primary btn-xs" disabled>At renewal</button>
        </div>
        <div style="margin-top: 5px;">
            <strong>Cancel subscription:</strong>
            <button type="button" class="btn btn-danger btn-xs" disabled>Immediately</button>
            <button type="button" class="btn btn-danger btn-xs" disabled>At next payment</button>
            <button type="button" class="btn btn-danger btn-xs" disabled>At renewal</button>
        </div>
    {{/if}}
</div>

<div class="clearfix" style="margin-bottom: 10px;"></div>

{{#if loading}}
    <div class="text-muted">Loading subscriptions...</div>
{{/if}}

{{#if error}}
    <div class="text-danger">{{error}}</div>
{{/if}}

{{#unless loading}}
    {{#unless error}}
        {{#if subscriptions.length}}

            {{#if activeSubscriptions.length}}
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #3c763d;">Active Subscriptions</h4>
                <table class="table table-panel" style="margin-bottom: 30px;">
                    <thead>
                        <tr>
                            <th style="width: 20%">Name</th>
                            <th style="width: 15%">Start Date</th>
                            <th style="width: 15%">End Date</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 20%">Created</th>

                            <!-- DEBUG COLUMNS -->
                            <th style="width: 10%">Sub ID</th>
                            <th style="width: 10%">Zuora Sub ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each activeSubscriptions}}
                            <tr>
                                <td>{{{name}}}</td>
                                <td>{{start_date}}</td>
                                <td>{{end_date}}</td>
                                <td><span class="label label-success">{{status}}</span></td>
                                <td>{{created_at}}</td>

                                <!-- DEBUG VALUES -->
                                <td>{{id}}</td>
                                <td>{{zuora_subscription_id}}</td>
                            </tr>
                        {{/each}}
                    </tbody>
                </table>
            {{/if}}

            {{#if previousSubscriptions.length}}
                <h4 style="margin-top: 20px; margin-bottom: 15px; color: #8a6d3b;">Previous Subscriptions</h4>
                <table class="table table-panel">
                    <thead>
                        <tr>
                            <th style="width: 20%">Name</th>
                            <th style="width: 15%">Start Date</th>
                            <th style="width: 15%">End Date</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 20%">Created</th>

                            <!-- DEBUG COLUMNS -->
                            <th style="width: 10%">Sub ID</th>
                            <th style="width: 10%">Zuora Sub ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each previousSubscriptions}}
                            <tr>
                                <td>{{{name}}}</td>
                                <td>{{start_date}}</td>
                                <td>{{end_date}}</td>
                                <td><span class="label label-default">{{status}}</span></td>
                                <td>{{created_at}}</td>

                                <!-- DEBUG VALUES -->
                                <td>{{id}}</td>
                                <td>{{zuora_subscription_id}}</td>
                            </tr>
                        {{/each}}
                    </tbody>
                </table>
            {{/if}}

        {{else}}
            <div class="text-muted">No subscriptions found for this account.</div>
        {{/if}}
    {{/unless}}
{{/unless}}
