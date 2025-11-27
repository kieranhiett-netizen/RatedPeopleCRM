{{#if loading}}
    <div class="text-muted">Loading subscriptions...</div>
{{/if}}

{{#if error}}
    <div class="text-danger">{{error}}</div>
{{/if}}

{{#unless loading}}
    {{#unless error}}
        {{#if subscriptions.length}}
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
                    {{#each subscriptions}}
                        <tr>
                            <td>{{name}}</td>
                            <td>{{start_date}}</td>
                            <td>{{end_date}}</td>
                            <td><span class="label label-{{#ifEqual status "Active"}}success{{else}}default{{/ifEqual}}">{{status}}</span></td>
                            <td>{{created_at}}</td>
                        </tr>
                    {{/each}}
                </tbody>
            </table>
        {{else}}
            <div class="text-muted">No subscriptions found for this account.</div>
        {{/if}}
    {{/unless}}
{{/unless}}