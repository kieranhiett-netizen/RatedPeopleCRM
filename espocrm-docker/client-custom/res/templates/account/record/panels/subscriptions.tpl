{{#if activeSubscriptions.length}}
    <h4 style="margin-top: 0; margin-bottom: 15px; color:#3c763d;">Active Subscriptions</h4>
    <table class="table table-panel" style="margin-bottom: 30px;">
        <thead>
            <tr>
                <th style="width: 25%">Name</th>
                <th style="width: 15%">Start Date</th>
                <th style="width: 15%">End Date</th>
                <th style="width: 15%">Status</th>
                <th style="width: 20%">Created</th>
                <th style="width: 10%">Actions</th>   {{!-- NEW --}}
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
                    <td>
                        <button
                            class="btn btn-xs btn-default action-change-subscription"
                            data-id="{{id}}"
                            data-zuora-subscription-id="{{zuora_subscription_id}}"
                        >
                            Change
                        </button>

                        <button
                            class="btn btn-xs btn-danger action-cancel-subscription"
                            data-id="{{id}}"
                            data-zuora-subscription-id="{{zuora_subscription_id}}"
                        >
                            Cancel
                        </button>
                    </td>
                </tr>
            {{/each}}
        </tbody>
    </table>
{{/if}}
