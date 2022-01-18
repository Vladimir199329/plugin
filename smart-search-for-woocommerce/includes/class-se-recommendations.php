<?php

defined('ABSPATH') || exit;

class SeRecommendations
{
    const BLOCK_TYPE_HOME     = 'home page';
    const BLOCK_TYPE_SEARCH   = 'search results';
    const BLOCK_TYPE_CATEGORY = 'category';
    const BLOCK_TYPE_PRODUCT  = 'product';
    const BLOCK_TYPE_CART     = 'cart';

    const WOOCOMMECE_CLASS = 'woocommerce';
    const ALIGN_WIDE_CLASS = 'alignwide';

    protected $wpdb;
    protected $lang_code;

    private $wc_content = '';

    public function __construct($lang_code)
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->lang_code = $lang_code;

        $this->init();
    }

    public function init()
    {
        if (defined('DOING_AJAX') && DOING_AJAX || defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        add_filter('the_content', array($this, 'addWordPressContent'), 10);
        add_filter('woocommerce_after_shop_loop', array($this, 'addWoocommerceContent'), 10);
        add_filter('woocommerce_after_single_product_summary', array($this, 'addAfterProductContent'), 10);
    }

    /**
     * Returns global woocommerce class
     * 
     * @return string
     */
    private function getWoocommerceClass()
    {
        return self::WOOCOMMECE_CLASS;
    }

    /**
     * Returns wide align theme css class
     * 
     * @return string
     */
    private function getAlignWideClass()
    {
        return get_template() != 'twentythirteen' ? self::ALIGN_WIDE_CLASS : '';
    }

    /**
     * Adds additional content after product content
     */
    public function addAfterProductContent()
    {
        if (is_product()) {
            global $product;
            $this->wc_content = $this->addToContent($this->wc_content, $this->getBlockContent(self::BLOCK_TYPE_PRODUCT, array($this->getWoocommerceClass(), $this->getAlignWideClass()), (array)$product->get_id()));
        }

        if (!empty($this->wc_content)) {
            echo $this->wc_content;
        }
    }

    /**
     * Adds additional content to Woocommerce pages
     */
    public function addWoocommerceContent()
    {
        // Woocommerce category page
        if (is_product_category()) {
            $this->wc_content = $this->addToContent($this->wc_content, $this->getBlockContent(self::BLOCK_TYPE_CATEGORY, array($this->getWoocommerceClass(), $this->getAlignWideClass())));
        }

        // Woocommerce default search page
        if (is_search()) {
            $this->wc_content = $this->addToContent($this->wc_content, $this->getBlockContent(self::BLOCK_TYPE_SEARCH, array($this->getWoocommerceClass(), 'is-style-wide')));
        }

        // Woocommerce home page
        if (is_shop() && !is_search()) {
            $this->wc_content = $this->addToContent($this->wc_content, $this->getBlockContent(self::BLOCK_TYPE_HOME, array($this->getWoocommerceClass(), $this->getAlignWideClass())));
        }

        if (!empty($this->wc_content)) {
            echo $this->wc_content;
        }
    }

    /**
	 * Filters the post content.
	 *
	 * @since 0.71
	 *
	 * @param string $content Content of the current post.
	 */
    public function addWordPressContent($content)
    {
        // Woocommerce home page
        if (is_front_page()) {
            $content = $this->addToContent($content, $this->getBlockContent(self::BLOCK_TYPE_HOME, array($this->getWoocommerceClass())));
        }

        // Searchanise search results page
        if (is_page(ApiSe::getInstance()->getSearchResultsPage())) {
            $content = $this->addToContent($content, $this->getBlockContent(self::BLOCK_TYPE_SEARCH, array('is-style-wide',)));
        }

        // Woocommerce cart page
        if (is_cart()) {
            $cart_product_ids = array();
            foreach(WC()->cart->get_cart() as $cart_item) {
                $cart_product_ids[] = $cart_item['product_id'];
            }
            $content = $this->addToContent($content, $this->getBlockContent(self::BLOCK_TYPE_CART, array($this->getWoocommerceClass(), 'woocommerce-cart'), $cart_product_ids));
        }

        return $content;
    }

    /**
     * Adds recommendation block content to WP page
     * 
     * @param string $main_content  WP page content
     * @param string $block_content Recommendation block content
     * 
     * @return string
     */
    private function addToContent($main_content, $block_content)
    {
        if (preg_match("/<!-- \/wp:cover -->/mu", $main_content)) {
            $result = preg_replace("/<!-- \/wp:cover -->/mu", "<!-- /wp:cover -->" . $block_content, $main_content);
        } else {
            $result = $main_content . $block_content;
        }

        return $result;
    }

    /**
     * Generates block html content
     * 
     * @param string $page_type   Type of recommendation block
     * @param array  $classes     List of css classes for block
     * @param array  $product_ids List of related product ids
     * 
     * @return string
     */
    private function getBlockContent($page_type, array $classes = array(), array $product_ids = array())
    {
        $classes[] = get_template();
        $product_ids_str = !empty($product_ids) ? ('data-product-ids = "' . implode(',', $product_ids) . '"') : '';
        $classes_str = implode(' ', $classes);

        return "<div class=\"snize-recommendation-wrapper {$classes_str}\" data-page-type = \"{$page_type}\" {$product_ids_str}></div>";
    }
}

// Init Searchanise Recommendations
add_action('plugins_loaded', function() {
    $GLOBALS['SearchaniseRecommendations'] = new SeRecommendations(ApiSe::getInstance()->getLocale());
});
