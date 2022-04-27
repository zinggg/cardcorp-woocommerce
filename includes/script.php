<?php
echo '<script type="text/javascript">
		var wpwlOptions = { 
		style: "plain",' . PHP_EOL;
echo 'locale: "' . $lang . '",' . PHP_EOL;
echo 'showCVVHint: true,
		brandDetection: true,
		showPlaceholders: true,
		autofocus : "card.number",
		showLabels: false,
		onReady: function() { 
		$(".wpwl-group-cvv").after( $(".wpwl-group-cardHolder").detach());';
echo   '$(".wpwl-group-cardNumber").after( $(".wpwl-group-cardHolder").detach());
		var visa = $(".wpwl-brand:first").clone().removeAttr("class").attr("class", "wpwl-brand-card wpwl-brand-custom wpwl-brand-VISA");
		var master = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MASTER");
		var maestro = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MAESTRO");
		var amex = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-AMEX");
		var diners = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-DINERS");
		var jcb = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-JCB");
		$(".wpwl-brand:first")';
if (strpos($this->cards, 'VISA') !== false) {
	echo '.after($(visa))';
}
if (strpos($this->cards, 'MASTER') !== false) {
	echo '.after($(master))';
}
if (strpos($this->cards, 'MAESTRO') !== false) {
	echo '.after($(maestro))';
}
if (strpos($this->cards, 'AMEX') !== false) {
	echo '.after($(amex))';
}
if (strpos($this->cards, 'DINERS') !== false) {
	echo '.after($(diners))';
}
if (strpos($this->cards, 'JCB') !== false) {
	echo '.after($(jcb))';
}
echo ';' . PHP_EOL;
echo '},
		onChangeBrand: function(e){
			$(".wpwl-brand-custom").css("opacity", "0.2");
			$(".wpwl-brand-" + e).css("opacity", "5"); 
		},
		onBeforeSubmitCard: function(){
			if ($(".wpwl-control-cardHolder").val()==""){
				$(".wpwl-control-cardHolder").addClass("wpwl-has-error");
				$(".wpwl-wrapper-cardHolder").append("<div class=\"wpwl-hint wpwl-hint-cardHolderError\">' .
	'Cardholder not valid' . '</div>");
			return false; }
		return true;}
	} </script>';
