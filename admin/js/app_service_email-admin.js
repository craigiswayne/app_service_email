jQuery(function($) {
	$("#select-all-logs").click(function() {
        const isChecked = $(this).prop("checked");
        $("tbody input[type='checkbox']").prop("checked", isChecked);
    });
});