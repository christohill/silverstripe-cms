<?php

namespace SilverStripe\CMS\Reports;

use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Reports\Report;

class EmptyPagesReport extends Report
{

    public function title()
    {
        return _t(__CLASS__.'.EMPTYPAGES', "Pages with no content");
    }

    public function group()
    {
        return _t(__CLASS__.'.ContentGroupTitle', "Content reports");
    }

    public function sort()
    {
        return 100;
    }

    public function sourceRecords($params = null)
    {
        return SiteTree::get()
            ->exclude('SiteTree.ClassName', RedirectorPage::class)
            ->filter('SiteTree.Content', [null, '', '<p></p>', '<p>&nbsp;</p>'])
            ->sort('Title');
    }

    public function columns()
    {
        return array(
            "Title" => array(
                "title" => "Title", // todo: use NestedTitle(2)
                "link" => true,
            ),
        );
    }
}
