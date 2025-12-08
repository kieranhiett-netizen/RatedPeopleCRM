{{#if loading}}
    <div class="text-muted">Loading case history...</div>
{{/if}}

{{#if error}}
    <div class="text-danger">{{error}}</div>
{{/if}}

{{#unless loading}}
    {{#unless error}}
        {{#if message}}
            <div class="text-muted">{{message}}</div>
        {{/if}}

        {{#if cases.length}}
            <h4 style="margin-top: 0; margin-bottom: 10px;">
                Case History ({{caseCount}})
            </h4>

            <table class="table table-panel">
                <thead>
                    <tr>
                        <th style="width: 10%;">Case #</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 25%;">Customer</th>
                        <th style="width: 25%;">Contact</th>
                        <th style="width: 25%;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    {{#each cases}}
                        <tr>
                            <td>
                                <strong>{{caseNumber}}</strong>
                            </td>

                            <td>
                                <span class="label {{statusLabelClass}}">
                                    {{status}}
                                </span>
                            </td>

                            <td>
                                {{#if suppliedCompany}}
                                    <div><strong>{{suppliedCompany}}</strong></div>
                                {{/if}}
                                {{#if suppliedName}}
                                    <div>{{suppliedName}}</div>
                                {{/if}}
                            </td>

                            <td>
                                {{#if suppliedEmail}}
                                    <div><i class="fas fa-envelope"></i> {{suppliedEmail}}</div>
                                {{/if}}
                                {{#if suppliedPhone}}
                                    <div><i class="fas fa-phone"></i> {{suppliedPhone}}</div>
                                {{/if}}
                            </td>

                            <td>
                                {{#if category}}
                                    <div><strong>Category:</strong> {{category}}</div>
                                {{/if}}
                                {{#if subCategory}}
                                    <div><strong>Sub:</strong> {{subCategory}}</div>
                                {{/if}}
                                {{#if subCategory2}}
                                    <div><strong>Sub 2:</strong> {{subCategory2}}</div>
                                {{/if}}
                                {{#if outcome}}
                                    <div><strong>Outcome:</strong> {{outcome}}</div>
                                {{/if}}
                                {{#if plan}}
                                    <div><strong>Plan:</strong> {{plan}}</div>
                                {{/if}}
                            </td>
                        </tr>
                    {{/each}}
                </tbody>
            </table>

        {{else}}
            <div class="text-muted">No case history found for this account.</div>
        {{/if}}
    {{/unless}}
{{/unless}}
