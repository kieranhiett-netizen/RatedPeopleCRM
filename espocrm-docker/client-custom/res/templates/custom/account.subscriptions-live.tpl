<div class="subscriptions-live-panel">

    {{#if loading}}
        <p>Loading subscriptions…</p>
    {{else}}
    
        {{#if error}}
            <div class="alert alert-danger">{{error}}</div>
        {{/if}}

        <button class="btn btn-default" data-action="refreshSubscriptions" style="margin-bottom:10px;">
            Refresh
        </button>

        {{#if rows.length}}
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Plan Code</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {{#each rows}}
                        <tr>
                            <td>{{plan_code}}</td>
                            <td>{{start_date}}</td>
                            <td>{{end_date}}</td>
                            <td>{{status}}</td>
                        </tr>
                    {{/each}}
                </tbody>
            </table>
        {{else}}
            <p>No subscriptions found.</p>
        {{/if}}

    {{/if}}

</div>
