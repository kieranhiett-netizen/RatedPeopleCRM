<div class="panel panel-default">
    <div class="panel-heading">
        <h4 class="panel-title">Subscriptions</h4>
    </div>
    <div class="panel-body">
        {{#if loading}}
            <div class="text-muted">Loading subscriptions...</div>
        {{/if}}
        
        {{#if error}}
            <div class="text-danger">{{error}}</div>
        {{/if}}
        
        {{#unless loading}}
            {{#unless error}}
                {{#if subscriptions.length}}
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{#each subscriptions}}
                                <tr>
                                    <td>{{id}}</td>
                                    <td>{{name}}</td>
                                    <td>{{status}}</td>
                                    <td>{{created_at}}</td>
                                    <td>{{modified_at}}</td>
                                </tr>
                            {{/each}}
                        </tbody>
                    </table>
                {{else}}
                    <div class="text-muted">No subscriptions found for this account.</div>
                {{/if}}
            {{/unless}}
        {{/unless}}
    </div>
</div>