<?php defined('BASEPATH') OR exit('No direct script access allowed');

// consider model as a driver for module or a stream

class Myseo_articles_m extends MY_Model
{
    // select
    private $select_fields = '
        myseo_articles_meta.id,
        journal.title as the_title,
        journal.slug as the_uri,
        myseo_articles_meta.title,
        myseo_articles_meta.keywords,
        myseo_articles_meta.description,
        myseo_articles_meta.no_index,
        myseo_articles_meta.no_follow
    ';

    private $options;

    public function __construct()
    {
        parent::__construct();

        $this->options = $this->db->get('myseo_options')->row();
    }

    // get categories
    public function categories()
    {
        

        $cats_raw = $this->db->select('id, title')->order_by('title')->get('journal_categories')->result();

        $cats = array('0' => lang('myseo:articles:filters:select_all'));

        // make array for form_dropdown
        foreach ($cats_raw as $cat)
        {
            $cats[$cat->id] = $cat->title;
        }

        return $cats;
    }

    // sync meta table with blog table
    public function sync_tables()
    {
        // get all blog articles
        $articles = $this->db->select('id')->get('journal')->result();

        foreach ($articles as $post)
        {
            // test in meta
            $count = $this->db->where('post_id', $post->id)->from('myseo_articles_meta')->count_all_results();

            if ($count == 0)
            {
                $this->db->insert('myseo_articles_meta', array('post_id' => $post->id));
            }
        }
    }

    // create tmp index of articles
    public function create_index($hash)
    {
     

        // filter articles
        $filters = $this->myseo_filters_m->get_type('articles');

        if ($filters->hide_drafts)
        {
            $this->db->where('journal.status', 'live');
        }

        if ($filters->category)
        {
            $this->db->where('journal.category_id', $filters->category);
        }

        if ($filters->by_title)
        {
            $this->db->like('journal.title', $filters->by_title);
        }

        if ($filters->by_slug)
        {
            $this->db->like('journal.slug', $filters->by_slug);
        }

        $articles = $this->db
            ->select('journal.id')
            ->join('myseo_articles_meta', 'journal.id=myseo_articles_meta.post_id', 'left')
            ->order_by('journal.created', 'desc')
            ->get('journal')->result();

        $index = array();

        foreach ($articles as $post)
        {
            $index[] = array(
                'hash' => $hash,
                'item_id' => $post->id
            );
        }

        // create filtered index
        if ( ! empty($index))
        {
            $this->db->insert_batch('myseo_index', $index);
        }

        return $this->db->where('hash', $hash)->from('myseo_index')->count_all_results();
    }

    // get articles
    public function get_articles($hash, $offset)
    {
     

        $articles = $this->db
            ->select($this->select_fields)
            ->where('myseo_index.hash', $hash)
            ->join('myseo_articles_meta', 'myseo_index.item_id=myseo_articles_meta.post_id', 'left')
            ->join('journal', 'journal.id=myseo_articles_meta.post_id', 'left')
            ->order_by('myseo_index.id')
            ->get('myseo_index', $this->options->pagination_limit, $offset)->result();

        // get actual keywords from pyro logic
        for ($i = 0;$i < count($articles);$i++)
        {
            $articles[$i]->keywords = Keywords::get_string($articles[$i]->keywords);
        }

        return $articles;
    }

    // gets list og blog articles
    public function get_list($offset)
    {


        // add all articles to metadata table
        $this->sync_tables();

        $hash = md5(time() + microtime());

        $index_count = $this->create_index($hash);

        // get post meta
        $articles = $this->get_articles($hash, $offset);

        $this->db->delete('myseo_index', array('hash' => $hash));

        return array($articles, $index_count);
    }
}