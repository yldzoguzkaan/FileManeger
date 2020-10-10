<?php
error_reporting( error_reporting() & ~E_NOTICE );
$dogrudan_baglanti = true; // Link oluşturmak için
$klasor_gosterme_izni = true; // Tüm alt dizinleri gizlemek için
$gizli_dosyalar = ['*.php','.*']; // Dizinlerdeki gizli dosyalar için
$dosya = $_REQUEST['file'] ?: '.';

//Listeleme methodu çağırıldığında tek tek tüm klasörlerin içini alıp list arrayıne setler
if($_GET['do'] == 'list') {
	if (is_dir($dosya)) {
		$directory = $dosya;
		$result = [];
		$dosyalar = array_diff(scandir($directory), ['.','..']);
		foreach ($dosyalar as $entry) if (!is_entry_ignored($entry, $klasor_gosterme_izni, $gizli_dosyalar)) {
			$i = $directory . '/' . $entry;
			$stat = stat($i);
			$result[] = [
				'size' => $stat['size'],
				'name' => basename($i),
				'path' => preg_replace('@^\./@', '', $i),
				'is_dir' => is_dir($i),
			];
		}
		usort($result,function($f1,$f2){
			$f1_key = ($f1['is_dir']?:2) . $f1['name'];
			$f2_key = ($f2['is_dir']?:2) . $f2['name'];
			return $f1_key > $f2_key;
		});
	} else {
		err(412,"Not a Directory");
	}
	echo json_encode(['success' => true, 'is_writable' => is_writable($dosya), 'results' =>$result]);
	exit;
}  elseif ($_POST['do'] == 'mkdir' && $allow_create_folder) {
	// root katmana ulaşma linklerini iptal etmek için
	$dir = $_POST['name'];
	$dir = str_replace('/', '', $dir);
	if(substr($dir, 0, 2) === '..')
	    exit;
	chdir($dosya);
	@mkdir($_POST['name']);
	exit;
} 
// main dizininden itibaren listelenenleri tekrar listelememek için
function is_entry_ignored($entry, $klasor_gosterme_izni, $gizli_dosyalar) {
	if ($entry === basename(__FILE__)) {
		return true;
	}

	if (is_dir($entry) && !$klasor_gosterme_izni) {
		return true;
	}
	foreach($gizli_dosyalar as $pattern) {
		if(fnmatch($pattern,$entry)) {
			return true;
		}
	}
	return false;
}
// byte cevirimi
function asBytes($ini_v) {
	$ini_v = trim($ini_v);
	$s = ['g'=> 1<<30, 'm' => 1<<20, 'k' => 1<<10];
	return intval($ini_v) * ($s[strtolower(substr($ini_v,-1))] ?: 1);
}
$MAX_UPLOAD_SIZE = min(asBytes(ini_get('post_max_size')), asBytes(ini_get('upload_max_filesize')));
?>
<!DOCTYPE html>
<html><head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<!-- Kod icinde ajax dili kullanıldığından kütüphanesi çekildi -->
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
<script>
//kalınan yerleri cokie icine kaydedip tekrar geri dönmemizi sağlamaktadır.
$(function(){
	var XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
	var MAX_UPLOAD_SIZE = <?php echo $MAX_UPLOAD_SIZE ?>;
	var $tbody = $('#list');
	$(window).on('hashchange',list).trigger('hashchange');
	$('#table').tablesorter();

	$('#table').on('click','.delete',function(data) {
		$.post("",{'do':'delete',file:$(this).attr('data-file'),xsrf:XSRF},function(response){
			list();
		},'json');
		return false;
	});
<?php if($allow_upload): ?>
	
//listeleme methodu calıstırılarak ekrana liste verilerini yazdırır	
<?php endif; ?>
	function list() {
		var hashval = window.location.hash.substr(1);
		$.get('?do=list&file='+ hashval,function(data) {
			$tbody.empty();
			$('#breadcrumb').empty().html(pathOlustur(hashval));
			if(data.success) {
				$.each(data.results,function(k,v){
					$tbody.append(dosyaSatiriOlustur(v));
				});
				!data.results.length && $tbody.append('<tr><td class="empty" colspan=5>This folder is empty</td></tr>')
				data.is_writable ? $('body').removeClass('no_write') : $('body').addClass('no_write');
			} else {
				console.warn(data.error.msg);
			}
			$('#table').retablesort();
		},'json');
	}
	//adres dizini icin veriler doldurulur 
	function dosyaSatiriOlustur(data) {
		var $link = $('<a class="name" />')
			.attr('href', data.is_dir ? '#' + encodeURIComponent(data.path) : './' + data.path)
			.text(data.name);
		var dogrudan_baglanti = <?php echo $dogrudan_baglanti?'true':'false'; ?>;
        	if (!data.is_dir && !dogrudan_baglanti)  $link.css('pointer-events','none');
		var $dl_link = $('<a/>').attr('href','?do=download&file='+ encodeURIComponent(data.path))
			.addClass('download').text('download');
		var $delete_link = $('<a href="#" />').attr('data-file',data.path).addClass('delete').text('delete');
		var perms = [];
		if(data.is_readable) perms.push('read');
		if(data.is_writable) perms.push('write');
		if(data.is_executable) perms.push('exec');
		var $html = $('<tr />')
			.addClass(data.is_dir ? 'is_dir' : '')
			.append( $('<td class="first" />').append($link) )
			.append( $('<td/>').attr('data-sort',data.is_dir ? -1 : data.size)
				.html($('<span class="size" />').text(dosyaBoyutuFormati(data.size))) )
		return $html;
	}
	//adres dizini icin path olusturulur
	function pathOlustur(path) {
		var base = "",
			$html = $('<div/>').append( $('<a href=#>Dosyalar</a></div>') );
		$.each(path.split('%2F'),function(k,v){
			if(v) {
				var v_as_text = decodeURIComponent(v);
				$html.append( $('<span/>').text(' ▸ ') )
					.append( $('<a/>').attr('href','#'+base+v).text(v_as_text) );
				base += v + '%2F';
			}
		});
		return $html;
	}
	//sadece byte tipini değil tüm tipleri yazdırmak icin convert işlemi yapılur
	function dosyaBoyutuFormati(bytes) {
		var s = ['bytes', 'KB','MB','GB','TB','PB','EB'];
		for(var pos = 0;bytes >= 1000; pos++,bytes /= 1024);
		var d = Math.round(bytes*10);
		return pos ? [parseInt(d/10),".",d%10," ",s[pos]].join('') : bytes + ' bytes';
	}
})

</script>
</head><body>

<div id="top">
   
	<p>
	Adres:
	<div id="breadcrumb">
	&nbsp;
	</div>
	</p>
</div>
<div>
	<p> Dosyalar: </p>
</div>
<div id="upload_progress"></div>
<table id="table" border = 1><thead><tr>
	<th>Dosya İsmi</th>
	<th>Dosya Boyutu</th>
</tr></thead><tbody id="list">

</tbody></table>
</body></html>