<?php
// PukiWiki - Yet another WikiWikiWeb clone
// $Id: rename.inc.php,v 1.31.5 2007/05/20 14:14:44 miko Exp $
// Copyright (C)
//   2005-2007 PukiWiki Plus! Team
//   2002-2005, 2007 PukiWiki Developers Team
// License: GPL v2 or (at your option) any later version
//
// Rename plugin: Rename page-name and related data
//
// Usage: http://path/to/pukiwikiphp?plugin=rename[&refer=page_name]

define('PLUGIN_RENAME_LOGPAGE', ':RenameLog');

function plugin_rename_init()
{
	$messages = array(
		'_rename_messages' => array(
			'err'            => _('<p>error:%s</p>'),
			'err_nomatch'    => _('no matching page(s)'),
			'err_notvalid'   => _('the new name is invalid.'),
			'err_adminpass'  => _('Incorrect administrator password.'),
			'err_notpage'    => _('%s is not a valid pagename.'),
			'err_norename'   => _('cannot rename %s.'),
			'err_already'    => _('already exists :%s.'),
			'err_already_below' => _('The following files already exist.'),
			'msg_title'      => _('Rename page'),
			'msg_page'       => _('specify source page name'),
			'msg_regex'      => _('rename with regular expressions.'),
			'msg_related'    => _('related pages'),
			'msg_do_related' => _('A related page is also renamed.'),
			'msg_rename'     => _('rename %s'),
			'msg_oldname'    => _('current page name'),
			'msg_newname'    => _('new page name'),
			'msg_adminpass'  => _('Administrator password'),
			'msg_arrow'      => _('->'),
			'msg_exist_none' => _('page is not processed when it already exists.'),
			'msg_exist_overwrite' => _('page is overwritten when it already exists.'),
			'msg_confirm'    => _('The following files will be renamed.'),
			'msg_result'     => _('The following files have been overwritten.'),
			'btn_submit'     => _('Submit'),
			'btn_next'       => _('Next')
		),
	);
	set_plugin_messages($messages);
}

function plugin_rename_action()
{
	global $whatsnew;

	// if (PKWK_READONLY) die_message('PKWK_READONLY prohibits this');
	if (auth::check_role('readonly')) die_message('PKWK_READONLY prohibits this');

	$method = plugin_rename_getvar('method');
	if ($method == 'regex') {
		$src = plugin_rename_getvar('src');
		if ($src == '') return plugin_rename_phase1();

		$src_pattern = '/' . preg_quote($src, '/') . '/';
		$arr0 = preg_grep($src_pattern, auth::get_existpages());
		if (! is_array($arr0) || empty($arr0))
			return plugin_rename_phase1('nomatch');

		$dst = plugin_rename_getvar('dst');
		$arr1 = preg_replace($src_pattern, $dst, $arr0);
		foreach ($arr1 as $page)
			if (! is_pagename($page))
				return plugin_rename_phase1('notvalid');

		return plugin_rename_regex($arr0, $arr1);

	} else {
		// $method == 'page'
		$page  = plugin_rename_getvar('page');
		$refer = plugin_rename_getvar('refer');

		if ($refer === '') {
			return plugin_rename_phase1();

		} else if (! is_page($refer)) {
			return plugin_rename_phase1('notpage', $refer);

		} else if ($refer === $whatsnew) {
			return plugin_rename_phase1('norename', $refer);

		} else if ($page === '' || $page === $refer) {
			return plugin_rename_phase2();

		} else if (! is_pagename($page)) {
			return plugin_rename_phase2('notvalid');

		} else {
			return plugin_rename_refer();
		}
	}
}

// 変数を取得する
function plugin_rename_getvar($key)
{
	global $vars;
	return isset($vars[$key]) ? $vars[$key] : '';
}

// エラーメッセージを作る
function plugin_rename_err($err, $page = '')
{
	global $_rename_messages;

	if ($err == '') return '';

	$body = $_rename_messages['err_' . $err];
	if (is_array($page)) {
		$tmp = '';
		foreach ($page as $_page) $tmp .= '<br />' . $_page;
		$page = $tmp;
	}
	if ($page != '') $body = sprintf($body, htmlspecialchars($page));

	$msg = sprintf($_rename_messages['err'], $body);
	return $msg;
}

//第一段階:ページ名または正規表現の入力
function plugin_rename_phase1($err = '', $page = '')
{
	global $script, $_rename_messages;

	$msg    = plugin_rename_err($err, $page);
	$refer  = plugin_rename_getvar('refer');
	$method = plugin_rename_getvar('method');

	$radio_regex = $radio_page = '';
	if ($method == 'regex') {
		$radio_regex = ' checked="checked"';
	} else {
		$radio_page  = ' checked="checked"';
	}
	$select_refer = plugin_rename_getselecttag($refer);

	$s_src = htmlspecialchars(plugin_rename_getvar('src'));
	$s_dst = htmlspecialchars(plugin_rename_getvar('dst'));

	$ret = array();
	$ret['msg']  = $_rename_messages['msg_title'];
	$ret['body'] = <<<EOD
$msg
<form action="$script" method="post">
 <div>
  <input type="hidden" name="plugin" value="rename" />
  <input type="radio"  name="method" id="_p_rename_page" value="page"$radio_page />
  <label for="_p_rename_page">{$_rename_messages['msg_page']}:</label>$select_refer<br />
  <input type="radio"  name="method" id="_p_rename_regex" value="regex"$radio_regex />
  <label for="_p_rename_regex">{$_rename_messages['msg_regex']}:</label><br />
  <label for="_p_rename_from">From:</label><br />
  <input type="text" name="src" id="_p_rename_from" size="80" value="$s_src" /><br />
  <label for="_p_rename_to">To:</label><br />
  <input type="text" name="dst" id="_p_rename_to"   size="80" value="$s_dst" /><br />
  <input type="submit" value="{$_rename_messages['btn_next']}" /><br />
 </div>
</form>
EOD;
	return $ret;
}

//第二段階:新しい名前の入力
function plugin_rename_phase2($err = '')
{
	global $script, $_rename_messages;

	$msg   = plugin_rename_err($err);
	$page  = plugin_rename_getvar('page');
	$refer = plugin_rename_getvar('refer');
	if ($page == '') $page = $refer;

	$msg_related = '';
	$related = plugin_rename_getrelated($refer);
	if (! empty($related))
		$msg_related = '<label for="_p_rename_related">' . $_rename_messages['msg_do_related'] . '</label>' .
		'<input type="checkbox" name="related" id="_p_rename_related" value="1" checked="checked" /><br />';

	$msg_rename = sprintf($_rename_messages['msg_rename'], make_pagelink($refer));
	$s_page  = htmlspecialchars($page);
	$s_refer = htmlspecialchars($refer);

	$ret = array();
	$ret['msg']  = $_rename_messages['msg_title'];
	$ret['body'] = <<<EOD
$msg
<form action="$script" method="post">
 <div>
  <input type="hidden" name="plugin" value="rename" />
  <input type="hidden" name="refer"  value="$s_refer" />
  $msg_rename<br />
  <label for="_p_rename_newname">{$_rename_messages['msg_newname']}:</label>
  <input type="text" name="page" id="_p_rename_newname" size="80" value="$s_page" /><br />
  $msg_related
  <input type="submit" value="{$_rename_messages['btn_next']}" /><br />
 </div>
</form>
EOD;
	if (! empty($related)) {
		$ret['body'] .= '<hr /><p>' . $_rename_messages['msg_related'] . '</p><ul>';
		sort($related, SORT_STRING);
		foreach ($related as $name)
			$ret['body'] .= '<li>' . make_pagelink($name) . '</li>';
		$ret['body'] .= '</ul>';
	}
	return $ret;
}

//ページ名と関連するページを列挙し、phase3へ
function plugin_rename_refer()
{
	$page  = plugin_rename_getvar('page');
	$refer = plugin_rename_getvar('refer');

	$pages[encode($refer)] = encode($page);
	if (plugin_rename_getvar('related') != '') {
		$from = strip_bracket($refer);
		$to   = strip_bracket($page);
		foreach (plugin_rename_getrelated($refer) as $_page)
			$pages[encode($_page)] = encode(str_replace($from, $to, $_page));
	}
	return plugin_rename_phase3($pages);
}

//正規表現でページを置換
function plugin_rename_regex($arr_from, $arr_to)
{
	$exists = array();
	foreach ($arr_to as $page)
		if (is_page($page))
			$exists[] = $page;

	if (! empty($exists)) {
		return plugin_rename_phase1('already', $exists);
	} else {
		$pages = array();
		foreach ($arr_from as $refer)
			$pages[encode($refer)] = encode(array_shift($arr_to));
		return plugin_rename_phase3($pages);
	}
}

function plugin_rename_phase3($pages)
{
	global $script, $_rename_messages, $vars;

	$msg = $input = '';
	$files = plugin_rename_get_files($pages);

	$exists = array();
	foreach ($files as $_page=>$arr)
		foreach ($arr as $old=>$new)
			if (file_exists($new))
				$exists[$_page][$old] = $new;

	if ( isset($vars['menu']) && ! auth::check_role('role_adm_contents') ) {
		return plugin_rename_proceed($pages, $files, $exists);
	}

	$pass = plugin_rename_getvar('pass');
	if ($pass != '' && pkwk_login($pass)) {
		return plugin_rename_proceed($pages, $files, $exists);
	} else if ($pass != '') {
		$msg = plugin_rename_err('adminpass');
	}

	$method = plugin_rename_getvar('method');
	if ($method == 'regex') {
		$s_src = htmlspecialchars(plugin_rename_getvar('src'));
		$s_dst = htmlspecialchars(plugin_rename_getvar('dst'));
		$msg   .= $_rename_messages['msg_regex'] . '<br />';
		$input .= '<input type="hidden" name="method" value="regex" />';
		$input .= '<input type="hidden" name="src"    value="' . $s_src . '" />';
		$input .= '<input type="hidden" name="dst"    value="' . $s_dst . '" />';
	} else {
		$s_refer   = htmlspecialchars(plugin_rename_getvar('refer'));
		$s_page    = htmlspecialchars(plugin_rename_getvar('page'));
		$s_related = htmlspecialchars(plugin_rename_getvar('related'));
		$msg   .= $_rename_messages['msg_page'] . '<br />';
		$input .= '<input type="hidden" name="method"  value="page" />';
		$input .= '<input type="hidden" name="refer"   value="' . $s_refer   . '" />';
		$input .= '<input type="hidden" name="page"    value="' . $s_page    . '" />';
		$input .= '<input type="hidden" name="related" value="' . $s_related . '" />';
	}

	if (! empty($exists)) {
		$msg .= $_rename_messages['err_already_below'] . '<ul>';
		foreach ($exists as $page=>$arr) {
			$msg .= '<li>' . make_pagelink(decode($page));
			$msg .= $_rename_messages['msg_arrow'];
			$msg .= htmlspecialchars(decode($pages[$page]));
			if (! empty($arr)) {
				$msg .= '<ul>' . "\n";
				foreach ($arr as $ofile=>$nfile)
					$msg .= '<li>' . $ofile .
					$_rename_messages['msg_arrow'] . $nfile . '</li>' . "\n";
				$msg .= '</ul>';
			}
			$msg .= '</li>' . "\n";
		}
		$msg .= '</ul><hr />' . "\n";

		$input .= '<input type="radio" name="exist" value="0" checked="checked" />' .
			$_rename_messages['msg_exist_none'] . '<br />';
		$input .= '<input type="radio" name="exist" value="1" />' .
			$_rename_messages['msg_exist_overwrite'] . '<br />';
	}

	$ret = array();
	$auth = '';
	if (auth::check_role('role_adm_contents')) {
		$auth = <<<EOD
  <label for="_p_rename_adminpass">{$_rename_messages['msg_adminpass']}</label>
  <input type="password" name="pass" id="_p_rename_adminpass" value="" />
EOD;
	}
	$ret['msg'] = $_rename_messages['msg_title'];
	$ret['body'] = <<<EOD
<p>$msg</p>
<form action="$script" method="post">
 <div>
  <input type="hidden" name="plugin" value="rename" />
  <input type="hidden" name="menu"   value="1" />
  $input
$auth
  <input type="submit" value="{$_rename_messages['btn_submit']}" />
 </div>
</form>
<p>{$_rename_messages['msg_confirm']}</p>
EOD;

	ksort($pages, SORT_STRING);
	$ret['body'] .= '<ul>' . "\n";
	foreach ($pages as $old=>$new)
		$ret['body'] .= '<li>' .  make_pagelink(decode($old)) .
			$_rename_messages['msg_arrow'] .
			htmlspecialchars(decode($new)) .  '</li>' . "\n";
	$ret['body'] .= '</ul>' . "\n";
	return $ret;
}

function plugin_rename_get_files($pages)
{
	global $log,$trackback,$referer;

	$files = array();
	$dirs  = array(BACKUP_DIR, DIFF_DIR, DATA_DIR);
	if (exist_plugin_convert('attach'))  $dirs[] = UPLOAD_DIR;
	if (exist_plugin_convert('counter')) $dirs[] = COUNTER_DIR;
	if ($trackback > 0 || $referer > 0) $dirs[] = TRACKBACK_DIR;
	foreach(array('update','download','browse') as $log_subdir) {
		if ($log[$log_subdir]['use']) $dirs[] = LOG_DIR.$log_subdir.'/';
	}
	// and more ...

	$matches = array();
	foreach ($dirs as $path) {
		$dir = opendir($path);
		if (! $dir) continue;

		while ($file = readdir($dir)) {
			if ($file == '.' || $file == '..') continue;

			foreach ($pages as $from=>$to) {
				$pattern = '/^' . str_replace('/', '\/', $from) . '([._].+)$/';
				if (! preg_match($pattern, $file, $matches))
					continue;

				$newfile = $to . $matches[1];
				$files[$from][$path . $file] = $path . $newfile;
			}
		}
	}
	return $files;
}

function plugin_rename_proceed($pages, $files, $exists)
{
	global $now, $_rename_messages;

	if (plugin_rename_getvar('exist') == '')
		foreach ($exists as $key=>$arr)
			unset($files[$key]);

	set_time_limit(0);
	foreach ($files as $page=>$arr) {
		foreach ($arr as $old=>$new) {
			if (isset($exists[$page][$old]) && $exists[$page][$old])
				unlink($new);
			rename($old, $new);

			// linkデータベースを更新する BugTrack/327 arino
			links_update($old);
			links_update($new);
		}
	}

	$postdata = get_source(PLUGIN_RENAME_LOGPAGE);
	$postdata[] = '*' . $now . "\n";
	if (plugin_rename_getvar('method') == 'regex') {
		$postdata[] = '-' . $_rename_messages['msg_regex'] . "\n";
		$postdata[] = '--From:[[' . plugin_rename_getvar('src') . ']]' . "\n";
		$postdata[] = '--To:[['   . plugin_rename_getvar('dst') . ']]' . "\n";
	} else {
		$postdata[] = '-' . $_rename_messages['msg_page'] . "\n";
		$postdata[] = '--From:[[' . plugin_rename_getvar('refer') . ']]' . "\n";
		$postdata[] = '--To:[['   . plugin_rename_getvar('page')  . ']]' . "\n";
	}

	if (! empty($exists)) {
		$postdata[] = "\n" . $_rename_messages['msg_result'] . "\n";
		foreach ($exists as $page=>$arr) {
			$postdata[] = '-' . decode($page) .
				$_rename_messages['msg_arrow'] . decode($pages[$page]) . "\n";
			foreach ($arr as $ofile=>$nfile)
				$postdata[] = '--' . $ofile .
					$_rename_messages['msg_arrow'] . $nfile . "\n";
		}
		$postdata[] = '----' . "\n";
	}

	foreach ($pages as $old=>$new)
		$postdata[] = '-' . decode($old) .
			$_rename_messages['msg_arrow'] . decode($new) . "\n";

	// 更新の衝突はチェックしない。

	// ファイルの書き込み
	page_write(PLUGIN_RENAME_LOGPAGE, join('', $postdata));

	//リダイレクト
	$page = plugin_rename_getvar('page');
	if ($page == '') $page = PLUGIN_RENAME_LOGPAGE;

	pkwk_headers_sent();
	header('Location: ' . get_page_location_uri($page));
	exit;
}

function plugin_rename_getrelated($page)
{
	$related = array();
	$pages = auth::get_existpages();
	$pattern = '/(?:^|\/)' . preg_quote(strip_bracket($page), '/') . '(?:\/|$)/';
	foreach ($pages as $name) {
		if ($name === $page) continue;
		if (preg_match($pattern, $name)) $related[] = $name;
	}
	return $related;
}

function plugin_rename_getselecttag($page)
{
	global $whatsnew;

	$pages = array();
	foreach (auth::get_existpages() as $_page) {
		if ($_page === $whatsnew) continue;

		$selected = ($_page === $page) ? ' selected' : '';
		$s_page = htmlspecialchars($_page);
		$pages[$_page] = '<option value="' . $s_page . '"' . $selected . '>' .
			$s_page . '</option>';
	}
	ksort($pages, SORT_STRING);
	$list = join("\n" . ' ', $pages);

	return <<<EOD
<select name="refer">
 <option value=""></option>
 $list
</select>
EOD;

}
?>
