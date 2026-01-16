(function($){

    // ensure script binding works even inside WooCommerce dynamic panels
    $('body').on('click', '#coolmo_ceu_copy_url', function(e){
        e.preventDefault();

        console.log("COPY BUTTON CLICKED");

        var $input = $('#coolmo_ceu_prefilled_url');

        if (!$input.length) {
            console.log("ERROR: #coolmo_ceu_prefilled_url not found");
            return;
        }

        var val = $input.val();

        if (!val) {
            console.log("ERROR: input value is empty");
            return;
        }

        // modern clipboard API first
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(function(){
                console.log("Copied via Clipboard API:", val);
            }).catch(function(err){
                console.log("Clipboard API failed:", err);
            });
            return;
        }

        // fallback method
        $input[0].select();
        $input[0].setSelectionRange(0, val.length);

        try {
            var result = document.execCommand('copy');
            console.log("execCommand result:", result);
        } catch(e) {
            console.log("execCommand failed:", e);
        }
    });

})(jQuery);