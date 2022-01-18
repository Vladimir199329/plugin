Searchanise = {};
Searchanise.host = SeOptions.host;
Searchanise.api_key = SeOptions.api_key;
Searchanise.SearchInput = SeOptions.search_input;

Searchanise.AutoCmpParams = {};
Searchanise.AutoCmpParams.union = {};
if (SeOptions.cur_label_for_usergroup != '') {
    Searchanise.AutoCmpParams.union.price = {};
    Searchanise.AutoCmpParams.union.price.min = SeOptions.cur_label_for_usergroup;
}
Searchanise.AutoCmpParams.restrictBy = {};
Searchanise.AutoCmpParams.restrictBy.visibility = 'visible|catalog|search';
Searchanise.AutoCmpParams.restrictBy.status = 'publish';
if (SeOptions.hide_out_of_stock_products == 'Y') {
    Searchanise.AutoCmpParams.restrictBy.is_in_stock = 'Y';
}
if (SeOptions.usergroup_ids) {
    Searchanise.AutoCmpParams.restrictBy.usergroup_ids = SeOptions.usergroup_ids;
}

Searchanise.ResultsParams = {};
Searchanise.ResultsParams.union = {};
if (SeOptions.cur_label_for_usergroup != '') {
    Searchanise.ResultsParams.union.price = {};
    Searchanise.ResultsParams.union.price.min = SeOptions.cur_label_for_usergroup;
}
Searchanise.ResultsParams.restrictBy = {};
Searchanise.ResultsParams.restrictBy.visibility = 'visible|catalog|search';
Searchanise.ResultsParams.restrictBy.status = 'publish';
if (SeOptions.hide_out_of_stock_products == 'Y') {
    Searchanise.ResultsParams.restrictBy.is_in_stock = 'Y';
}
if (SeOptions.usergroup_ids) {
    Searchanise.ResultsParams.restrictBy.usergroup_ids = SeOptions.usergroup_ids;
}
if (SeOptions.use_wp_jquery) {
    Searchanise.forceUseExternalJQuery = true;
}

Searchanise.options = {};
Searchanise.options.ResultsDiv = '#snize_results';
Searchanise.options.ResultsFormPath = SeOptions.results_form_path;
Searchanise.options.ResultsFallbackUrl = SeOptions.results_fallback_url;
Searchanise.options.ResultsAddToCartUrl = SeOptions.results_add_to_cart_url;

Searchanise.options.facetBy = {};
Searchanise.options.facetBy.price = {};
Searchanise.options.facetBy.price.type = 'slider';

Searchanise.options.PriceFormat = {
    rate : SeOptions.rate,
    symbol: SeOptions.symbol,
    decimals: SeOptions.decimals,
    decimals_separator: SeOptions.decimals_separator,
    thousands_separator: SeOptions.thousands_separator,
    after: SeOptions.currency_position_after
};

(function() {
    var __se = document.createElement('script');
    __se.src = SeOptions.host + '/widgets/v1.0/init.js';
    __se.setAttribute('async', 'true');
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(__se, s);
})();
