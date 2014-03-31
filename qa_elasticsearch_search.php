<?php

require_once('vendor/autoload.php');

class qa_elasticsearch_search {
    var $directory, $urltoroot;

    private $client = null;

    function load_module($directory, $urltoroot)
    {
        $this->directory=$directory;
        $this->urltoroot=$urltoroot;
    }


    function admin_form()
    {
        $saved=false;

        if (qa_clicked('elasticsearch_save_button')) {
            qa_opt('elasticsearch_enabled', qa_post_text('elasticsearch_enabled_field'));
            qa_opt('elasticsearch_endpoint', qa_post_text('elasticsearch_endpoint_field'));
            qa_opt('elasticsearch_index', qa_post_text('elasticsearch_index_field'));

            $saved=true;
        }

        $form=array(
            'ok' => $saved ? 'ElasticSearch settings saved' : null,

            'fields' => array(
                'enabled' => array(
                    'label' => 'ElasticSearch Enabled:',
                    'type' => 'checkbox',
                    'value' => qa_opt('elasticsearch_enabled'),
                    'tags' => 'NAME="elasticsearch_enabled_field"',
                ),
                'endpoint' => array(
                    'label' => 'ElasticSearch Endpoint URL:',
                    'value' => qa_opt('elasticsearch_endpoint'),
                    'tags' => 'NAME="elasticsearch_endpoint_field"',
                ),
                'index' => array(
                    'label' => 'Index name:',
                    'value' => qa_opt('elasticsearch_index'),
                    'tags' => 'NAME="elasticsearch_index_field"',
                ),

            ),

            'buttons' => array(
                array(
                    'label' => 'Save Changes',
                    'tags' => 'NAME="elasticsearch_save_button"',
                ),
            ),
        );

        return $form;
    }

    /**
     * @return \Elastica\Client
     */
    private function get_elasticsearch_client() {
        if (!qa_opt('elasticsearch_enabled')) return null;

        if ($this->client == null) {
            $endpoint = parse_url(qa_opt('elasticsearch_endpoint'));
            $elasticsearch_config = array(
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
                'path' => isset($endpoint['path']) ? $endpoint['path'] : '',
            );
            $this->client = new \Elastica\Client($elasticsearch_config);
//          $adapter = $client->getAdapter();
//          $adapter->setOptions(array('CURLOPT_ENCODING'=>'UTF-8'));
        }
        return $this->client;
    }

    private function purge_html($str) {
        // Strip HTML Tags
        $clear = strip_tags($str);

        // Clean up things like &amp;
        $clear = html_entity_decode($clear);

        // Strip out any url-encoded stuff
        $clear = urldecode($clear);

        // Replace non-AlNum characters with space
        //$clear = preg_replace('/[^A-Za-z0-9]/', ' ', $clear);

        // Replace Multiple spaces with single space
        $clear = preg_replace('/ +/', ' ', $clear);

        // Replace newlines with single space
        $clear = preg_replace('/[\n\r]+/', ' ', $clear);

        // Trim the string of leading/trailing space
        $clear = trim($clear);

        //return utf8_encode($clear);
        return $clear;
    }

    private function get_answers($questionid) {
        $result = qa_db_query_raw("SELECT * FROM qa_posts WHERE type='A' AND parentid='$questionid'");
        $answers = qa_db_read_all_assoc($result);
        return $answers;
    }

    private function get_comments($questionid) {
        $result = qa_db_query_raw("SELECT * FROM qa_posts WHERE type='C' AND parentid='$questionid'");
        $comments = qa_db_read_all_assoc($result);
        return $comments;
    }

    private function createIndex($delete_if_exists=false) {
        $client = $this->get_elasticsearch_client();
        $elasticaIndex = $client->getIndex(qa_opt('elasticsearch_index'));

        if (!$elasticaIndex->exists() || $delete_if_exists) {
            $elasticaIndex->create(
                array(
                    'number_of_shards' => 5,
                    'number_of_replicas' => 1,
//                    'analysis' => array(
//                        'analyzer' => array(
//                            'italian_analyzer' => array(
//                                'type' => 'snowball',
//                                'language' => 'Italian',
//                            ),
//                            'url_analyzer' => array(
//                                'type' => 'custom',
//                                'tokenizer' => 'lowercase',
//                                'filter' => array('stop', 'url_stop')
//                            )
//                        ),
//                        'filter' => array(
//                            'url_stop' => array(
//                                'type' => 'stop',
//                                'stopwords' => array('http', 'https')
//                            )
//                        )
//                    )
                ),
                $delete_if_exists
            );
        }

        return $elasticaIndex;
    }

    /**
     * @return \Elastica\Type
     */
    private function createMapping() {
        //Create a type

        //Create a type
        $client = $this->get_elasticsearch_client();
        $elasticaIndex = $client->getIndex(qa_opt('elasticsearch_index'));
        $elasticaType = $elasticaIndex->getType('qa_post');

        // Define mapping
        $mapping = new \Elastica\Type\Mapping();
        $mapping->setType($elasticaType);
//        $mapping->setParam('index_analyzer', 'italian_analyzer');
//        $mapping->setParam('search_analyzer', 'italian_analyzer');

        // Define boost field
        //$mapping->setParam('_boost', array('name' => '_boost', 'null_value' => 1.0));

        // Set mapping
        $mapping->setProperties(array(
            'user'    => array(
                'type' => 'object',
                'properties' => array(
                    'id'        => array('type' => 'integer', 'include_in_all' => TRUE),
                    'name'      => array('type' => 'string', 'include_in_all' => TRUE)
                ),
            ),
            'url'       => array('type' => 'string', 'type' => 'string', 'store'=>true, 'index'=>'not_analyzed'),
            'title'     => array('type' => 'string', 'include_in_all' => TRUE),
            'question'  => array('type' => 'string', 'include_in_all' => TRUE),
            'answers'   => array('type' => 'string', 'include_in_all' => TRUE, 'store'=>false),
            'n_answers' => array('type' => 'integer', 'include_in_all' => FALSE, 'store'=>true, 'index'=>'not_analyzed'),
            'comments'  => array('type' => 'string', 'include_in_all' => TRUE, 'store'=>false),
            'lastupdate'=> array('type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss', 'include_in_all' => FALSE),
        ));

        // Send mapping to type
        $mapping->send();

        return $elasticaType;
    }

    private function index_question($questionid) {
        if (!qa_opt('elasticsearch_enabled')) return true;

        $result = qa_db_query_raw('SELECT * FROM qa_posts WHERE postid=' . $questionid);
        $question = qa_db_read_one_assoc($result);
        $title = $this->purge_html($question['title']);
        $content = $this->purge_html($question['content']);

        $client = $this->get_elasticsearch_client();
        $elasticaIndex = $this->createIndex();
        $elasticaType = $this->createMapping();

        $answers = array();
        $comments = array();

        $doc = array(
            'url'       =>   qa_q_path($questionid, $title, true),
            'title'     =>   $title,
            'question'  =>   $content,
            'answers'   =>   $answers,
            'comments'   =>  $comments,

        );

        if ($question['updated'])
            $doc['lastupdate'] = $question['updated'];
        else
            $doc['lastupdate'] = $question['created'];

        foreach ($this->get_comments($questionid) as $c) {
            $comments[] = $c['content'];
        }

        foreach ($this->get_answers($questionid) as $a) {
            $answers[] = $this->purge_html($a['content']);
            foreach ($this->get_comments($a['postid']) as $c) {
                $comments[] = $c['content'];
            }
        }

        if ( count($answers) > 0 ) $doc['answers'] = $answers;
        if ( count($comments) > 0 ) $doc['comments'] = $comments;

        $doc['n_answers'] = count($answers);

        $esDocument = new \Elastica\Document($questionid, $doc);
        $elasticaType->addDocument($esDocument);
        $elasticaType->getIndex()->refresh();
    }

    function index_post($postid, $type, $questionid, $parentid, $title, $content, $format, $text, $tagstring, $categoryid) {
        $this->index_question($questionid);
    }


    function unindex_post($postid) {

    }

    function process_search($userquery, $start, $count, $userid, $absoluteurls, $fullcontent) {

        $results = array();

        // get the client instance
        $elasticaIndex = $elasticaIndex = $this->createIndex();

        // Define a Query. We want a string query.
        $elasticaQueryString  = new \Elastica\Query\QueryString();

        //'And' or 'Or' default : 'Or'
        $elasticaQueryString->setDefaultOperator('AND');
        $elasticaQueryString->setQuery($userquery);

        // Create the actual search object with some data.
        $elasticaQuery        = new \Elastica\Query();
        $elasticaQuery->setQuery($elasticaQueryString);

        //Search on the index.
        $elasticaResultSet    = $elasticaIndex->search($elasticaQuery);

        // show documents using the resultset iterator
        foreach ($elasticaResultSet as $result) {
            $data = $result->getData();
//            echo "<pre>";print_r($data);die();
            $item = array(
                'question_postid' => $result->getId(),
                //'match_postid' => $document['question_id'],
                //'page_pageid' => $document['question_id'],
                'title' => $data['title'],
                //'url' => $document['url'],
            );
            $results[] = $item;
        }

        return $results;
    }


}