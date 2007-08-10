<?php

/**
 * Represents an interface for viewing and adding page comments
 * Create one, passing the page discussed to the constructor.  It can then be
 * inserted into a template.
 */
class PageCommentInterface extends ViewableData {
	protected $controller, $methodName, $page;
	
	/**
	 * Create a new page comment interface
	 * @param controller The controller that the U
	 * @param methodName The method to return this PageCommentInterface object
	 * @param page The page that we're commenting on
	 */
	function __construct($controller, $methodName, $page) {
		$this->controller = $controller;
		$this->methodName = $methodName;
		$this->page = $page;
	}
	
	function forTemplate() {
		return $this->renderWith('PageCommentInterface');
	}
	
	function PostCommentForm() {
		Requirements::javascript('jsparty/behaviour.js');
		Requirements::javascript('jsparty/prototype.js');
		Requirements::javascript('jsparty/scriptaculous/effects.js');
		Requirements::javascript('cms/javascript/PageCommentInterface.js');
		
		
		$fields = new FieldSet(
			new HiddenField("ParentID", "ParentID", $this->page->ID),
			new TextField("Name", "Your name")
		);	
		if(MathSpamProtection::isEnabled()){
			$fields->push(new TextField("Math","Spam protection question: ".MathSpamProtection::getMathQuestion()));
		}				
		
		/*//TODO
		  if(CaptchaSpamProtection::isEnabled()){
			$fields->push(new TextField("Captcha",CaptchaSpamProtection::getImage()."<br /><br />Please copy down the text from the image above"));
		}	*/	
		
		$fields->push(new TextareaField("Comment", "Comments"));
		
		$form = new PageCommentInterface_Form($this->controller, $this->methodName . ".PostCommentForm",$fields, new FieldSet(
			new FormAction("postcomment", "Post")
		));
		
		$form->loadDataFrom(array(
			"Name" => Cookie::get("PageCommentInterface_Name"),
		));
		
		return $form;
	}
	
	function Comments() {
		// Comment limits
		if(isset($_GET['commentStart'])) {
			$limit = (int)$_GET['commentStart'].",".PageComment::$comments_per_page;
		} else {
			$limit = "0,".PageComment::$comments_per_page;
		}
		
		if(isset($_GET['showspam'])) {
			$comments = DataObject::get("PageComment", "ParentID = '" . Convert::raw2sql($this->page->ID) . "'", "Created DESC", "", $limit);
		} else {
			$comments = DataObject::get("PageComment", "ParentID = '" . Convert::raw2sql($this->page->ID) . "' AND IsSpam = 0", "Created DESC", "", $limit);
		}
		
		if(is_null($comments)) {
			return;
		}
		
		// This allows us to use the normal 'start' GET variables as well (In the weird circumstance where you have paginated comments AND something else paginated)
		$comments->setPaginationGetVar('commentStart');
		
		return $comments;
	}
	
	function CommentRssLink() {
		return Director::absoluteBaseURL() . "PageComment/rss?pageid=" . $this->page->ID;
	}
	
}

class PageCommentInterface_Form extends Form {
	function postcomment($data) {
		// Spam filtering
		if(SSAkismet::isEnabled()) {
			try {
				$akismet = new SSAkismet();
				
				$akismet->setCommentAuthor($data['Name']);
				$akismet->setCommentContent($data['Comment']);
				
				if($akismet->isCommentSpam()) {
					if(SSAkismet::getSaveSpam()) {
						$comment = Object::create('PageComment');
						$this->saveInto($comment);
						$comment->setField("IsSpam", true);
						$comment->write();
					}
					echo "<b>Spam detected!!</b><br /><br />";
					echo "If you believe this was in error, please email ";
					echo ereg_replace("@", " _(at)_", Email::getAdminEmail());
					echo ".<br /><br />The message you posted was:<br /><br />";
					echo $data['Comment'];
					
					return;
				}
			} catch (Exception $e) {
				// Akismet didn't work, continue without spam check
			}
		}
		
		//check if spam question was right.
		if(MathSpamProtection::isEnabled()){		
			if(!MathSpamProtection::correctAnswer($data['Math'])){
						if(Director::is_ajax()) {
							echo "<div class='BlogError'><p>You got the spam protection question wrong.</p></div>";
						} else {		
							Director::redirectBack();
						}
				return;
			}
		}
		
		/*
		if(CaptchaSpamProtection::isEnabled()){		
			if(!CaptchaSpamProtection::correctAnswer($data['Captcha'])){
				echo "<div class='BlogError'><p>You got the captcha protection question wrong.</p></div>";
				return;
			}
		}*/
		
		Cookie::set("PageCommentInterface_Name", $data['Name']);
		
		$comment = Object::create('PageComment');
		$this->saveInto($comment);
		$comment->IsSpam = false;
		$comment->NeedsModeration = PageComment::moderationEnabled();
		
		$comment->write();
		
		if(Director::is_ajax()) {
			echo $comment->renderWith('PageCommentInterface_singlecomment');
		} else {		
			Director::redirectBack();
		}
	}
}

?>