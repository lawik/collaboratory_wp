<?php

/*
 * manages links filters and url of translations on frontend
 *
 * @since 1.2
 */
class PLL_Frontend_Links extends PLL_Links {
	public $curlang, $home, $using_permalinks, $page_on_front, $page_for_posts;

	/*
	 * constructor
	 *
	 * @since 1.2
	 *
	 * @param object $links_model
	 */
	public function __construct(&$links_model) {
		parent::__construct($links_model);

		$this->using_permalinks = (bool) get_option('permalink_structure'); // are we using permalinks?

		$this->home = get_option('home');
		$this->page_on_front = get_option('page_on_front');
		$this->page_for_posts = get_option('page_for_posts');

		add_action('pll_language_defined', array(&$this, 'pll_language_defined'), 10, 2);
	}

	/*
	 * adds filters once the language is defined
	 * low priority on links filters to come after any other modification
	 *
	 * @since 1.2
	 *
	 * @param string $slug current language slug
	 * @param object $curlang current language object
	 */
	public function pll_language_defined($slug, $curlang) {
		$this->curlang = $curlang;

		// rewrites author and date links to filter them by language
		foreach (array('feed_link', 'author_link', 'post_type_archive_link', 'year_link', 'month_link', 'day_link') as $filter)
			add_filter($filter, array(&$this, 'archive_link'), 20);

		// modifies the page link in case the front page is not in the default language
		add_filter('page_link', array(&$this, 'page_link'), 20, 2);

		// meta in the html head section
		add_action('wp_head', array(&$this, 'wp_head'));

		// manages the redirection of the homepage
		add_filter('redirect_canonical', array(&$this, 'redirect_canonical'), 10, 2);

		// modifies the home url
		if (!defined('PLL_FILTER_HOME_URL') || PLL_FILTER_HOME_URL)
			add_filter('home_url', array(&$this, 'home_url'), 10, 2);
	}

	/*
	 * modifies the author and date links to add the language parameter (as well as feed link)
	 *
	 * @since 0.4
	 *
	 * @param string $link
	 * @return string modified link
	 */
	public function archive_link($link) {
		return $this->links_model->add_language_to_link($link, $this->curlang);
	}

	/*
	 * modifies post format links
	 *
	 * @since 0.7
	 *
	 * @param string $link
	 * @param object $term term object
	 * @param string $tax taxonomy name
	 * @return string modified link
	 */
	public function term_link($link, $term, $tax) {
		if (isset($this->links[$link]))
			return $this->links[$link];

		return $this->links[$link] = $tax == 'post_format' ?
			$this->links_model->add_language_to_link($link, $this->model->get_term_language($term->term_id)) : parent::term_link($link, $term, $tax);
	}

	/*
	 * modifies the page link in case the front page is not in the default language
	 *
	 * @since 0.7.2
	 *
	 * @param string $link
	 * @param int $id
	 * @return string modified link
	 */
	public function page_link($link, $id) {
		static $posts = array(); // cache the results

		if ($this->options['redirect_lang'] && $this->page_on_front && $lang = $this->model->get_post_language($id)) {
			if (!isset($posts[$lang->slug][$this->page_on_front]))
				$posts[$lang->slug][$this->page_on_front] = $this->model->get_post($this->page_on_front, $lang);
			if ($id == $posts[$lang->slug][$this->page_on_front])
				return $this->options['hide_default'] && $lang->slug == $this->options['default_lang'] ? trailingslashit($this->home) : get_term_link($lang, 'language');
		}

		if ($this->page_on_front && $this->options['hide_default']) {
			if (!isset($posts[$this->options['default_lang']][$this->page_on_front]))
				$posts[$this->options['default_lang']][$this->page_on_front] = $this->model->get_post($this->page_on_front, $this->options['default_lang']);
			if ($id == $posts[$this->options['default_lang']][$this->page_on_front])
				return trailingslashit($this->home);
		}

		return _get_page_link($id);
	}

	/*
	 * outputs references to translated pages (if exists) in the html head section
	 *
	 * @since 0.1
	 */
	public function wp_head() {
		// google recommends to include self link https://support.google.com/webmasters/answer/189077?hl=en
		foreach ($this->model->get_languages_list() as $language) {
			if ($url = $this->get_translation_url($language))
				$urls[$language->slug] = $url;
		}

		// ouptputs the section only if there are translations ($urls always contains self link)
		if (!empty($urls) && count($urls) > 1) {
			foreach ($urls as $lang => $url)
				printf('<link rel="alternate" href="%s" hreflang="%s" />'."\n", esc_url($url), esc_attr($lang));
		}
	}

	/*
	 * manages canonical redirection of the homepage when using page on front
	 *
	 * @since 0.1
	 *
	 * @param string $redirect_url
	 * @param string $requested_url
	 * @return bool|string modified url, false if redirection is canceled
	 */
	public function redirect_canonical($redirect_url, $requested_url) {
		global $wp_query;
		if (is_page() && !is_feed() && isset($wp_query->queried_object) && 'page' == get_option('show_on_front') && $wp_query->queried_object->ID == get_option('page_on_front'))
			return $this->options['redirect_lang'] ? $this->get_home_url() : false;
		return $redirect_url;
	}

	/*
	 * filters the home url to get the right language
	 *
	 * @since 0.4
	 *
	 * @param string $url
	 * @param string $path
	 * @return string
	 */
	public function home_url($url, $path) {
		if (!(did_action('template_redirect') || did_action('login_init')) || rtrim($url,'/') != $this->home)
			return $url;

		$white_list = apply_filters('pll_home_url_white_list',  array(
			array('file' => get_theme_root()),
			array('function' => 'wp_nav_menu'),
			array('function' => 'login_footer')
		));

		$black_list = apply_filters('pll_home_url_black_list',  array(array('function' => 'get_search_form')));

		foreach (array_reverse(debug_backtrace(/*!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS*/)) as $trace) {
			// searchform.php is not passed through get_search_form filter prior to WP 3.6
			// backward compatibility WP < 3.6
			if (isset($trace['file']) && strpos($trace['file'], 'searchform.php'))
				return $this->using_permalinks && version_compare($GLOBALS['wp_version'], '3.6', '<') ? $this->get_home_url($this->curlang, true) : $url;

			foreach ($black_list as $v) {
				if ((isset($trace['file'], $v['file']) && strpos($trace['file'], $v['file']) !== false) || (isset($trace['function'], $v['function']) && $trace['function'] == $v['function']))
					return $url;
			}

			foreach ($white_list as $v) {
				if ((isset($trace['function'], $v['function']) && $trace['function'] == $v['function']) ||
					(isset($trace['file'], $v['file']) && strpos($trace['file'], $v['file']) !== false && in_array($trace['function'], array('home_url', 'get_home_url', 'bloginfo', 'get_bloginfo'))))
					$ok = true;
			}
		}

		return empty($ok) ? $url : (empty($path) ? rtrim($this->get_home_url($this->curlang), '/') : $this->get_home_url($this->curlang));
	}

	/*
	 * returns the url of the translation (if exists) of the current page
	 *
	 * @since 0.1
	 *
	 * @param object $language
	 * @return string
	 */
	public function get_translation_url($language) {
		static $translation_url = array(); // used to cache results

		if (isset($translation_url[$language->slug]))
			return $translation_url[$language->slug];

		global $wp_query;
		$qv = $wp_query->query;
		$hide = $this->options['default_lang'] == $language->slug && $this->options['hide_default'];

		// post and attachment
		if (is_single() && ($this->options['media_support'] || !is_attachment()) && $id = $this->model->get_post($wp_query->queried_object_id, $language))
			$url = get_permalink($id);

		// page for posts
		// FIXME the last test should useless now since I test is_posts_page
		elseif ($wp_query->is_posts_page && !empty($wp_query->queried_object_id) && ($id = $this->model->get_post($wp_query->queried_object_id, $language)) && $id == $this->model->get_post($this->page_for_posts, $language))
			$url = get_permalink($id);

		elseif (is_page() && $id = $this->model->get_post($wp_query->queried_object_id, $language))
			$url = $hide && $id == $this->model->get_post($this->page_on_front, $language) ? $this->home : get_page_link($id);

		elseif (!is_tax('post_format') && !is_tax('language') && (is_category() || is_tag() || is_tax()) ) {
			$term = get_queried_object();
			$lang = $this->model->get_term_language($term->term_id);

			if (!$lang || $language->slug == $lang->slug)
				$url = get_term_link($term, $term->taxonomy); // self link
			elseif ($link_id = $this->model->get_translation('term', $term->term_id, $language))
				$url = get_term_link(get_term($link_id, $term->taxonomy), $term->taxonomy);
		}

		elseif (is_search())
			$url = $this->get_archive_url($language);

		elseif (is_archive()) {
			$keys = array('post_type', 'm', 'year', 'monthnum', 'day', 'author', 'author_name');
			// check if there are existing translations before creating the url
			if ($this->model->count_posts($language, array_intersect_key($qv, array_flip($keys))))
				$url = $this->get_archive_url($language);
		}

		elseif (is_home() || is_tax('language') )
			$url = $this->get_home_url($language);

		return $translation_url[$language->slug] = apply_filters('pll_translation_url', (isset($url) && !is_wp_error($url) ? $url : null), $language->slug);
	}

	/*
	 * get the translation of the current archive url
	 * used also for search
	 *
	 * @since 1.2
	 *
	 * @param object $language
	 * @return string
	 */
	public function get_archive_url($language) {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$url = $this->links_model->remove_language_from_link($url);
		$url = $this->links_model->add_language_to_link($url, $language);
		return $this->links_model->remove_paged_from_link($url);
	}

	/*
	 * returns the home url in the right language
	 *
	 * @since 0.1
	 *
	 * @param object $language optional defaults to current language
	 * @param bool $is_search optional wether we need the home url for a search form, defaults to false
	 */
	public function get_home_url($language = '', $is_search = false) {
		static $home_urls = array(); // used for cache

		if (empty($language))
			$language = $this->curlang;

		if (isset($home_urls[$language->slug][$is_search]))
			return $home_urls[$language->slug][$is_search];

		if ($this->options['default_lang'] == $language->slug && $this->options['hide_default'])
			return $home_urls[$language->slug][$is_search] = trailingslashit($this->home);

		// a static page is used as front page : /!\ don't use get_page_link to avoid infinite loop
		// don't use this for search form
		if (!$is_search && $this->page_on_front && $id = $this->model->get_post($this->page_on_front, $language))
			return $home_urls[$language->slug][$is_search] = $this->page_link('', $id);

		$link = get_term_link($language, 'language');

		// add a trailing slash as done by WP on homepage (otherwise could break the search form when the permalink structure does not include one)
		// only for pretty permalinks
		return $home_urls[$language->slug][$is_search] = $this->using_permalinks ? trailingslashit($link) : $link;
	}
}
