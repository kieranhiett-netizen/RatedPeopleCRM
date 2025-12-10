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
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #3c763d;">
                    Active Subscriptions
                </h4>
                <table class="table table-panel" style="margin-bottom: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 25%">Name</th>
                            <th style="width: 15%">Start Date</th>
                            <th style="width: 15%">End Date</th>
                            <th style="width: 15%">Status</th>
                            <th style="width: 20%">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each activeSubscriptions}}
                            <tr>
                                <td>{{name}}</td>
                                <td>{{start_date}}</td>
                                <td>{{end_date}}</td>
                                <td><span class="label label-success">{{status}}</span></td>
                                <td>{{created_at}}</td>
                            </tr>
                        {{/each}}
                    </tbody>
                </table>

                {{!-- ONE set of actions for the whole subscription group --}}
                <div class="text-center" style="margin: 15px 0 25px;">
                    <button
                        class="btn btn-default action-change-subscription-panel"
                        type="button"
                    >
                        Change Subscription
                    </button>

                    <button
                        class="btn btn-danger action-cancel-subscription-panel"
                        type="button"
                        style="margin-left: 10px;"
                    >
                        Cancel Subscription
                    </button>
                </div>
            {{/if}}
            
            {{#if previousSubscriptions.length}}
                <h4 style="margin-top: 20px; margin-bottom: 15px; color: #8a6d3b;">
                    Previous Subscriptions
                </h4>
                <table class="table table-panel">
                    <thead>
                        <tr>
                            <th style="width: 25%">Name</th>
                            <th style="width: 15%">Start Date</th>
                            <th style="width: 15%">End Date</th>
                            <th style="width: 15%">Status</th>
                            <th style="width: 20%">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each previousSubscriptions}}
                            <tr>
                                <td>{{name}}</td>
                                <td>{{start_date}}</td>
                                <td>{{end_date}}</td>
                                <td><span class="label label-default">{{status}}</span></td>
                                <td>{{created_at}}</td>
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
