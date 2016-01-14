<?php

/**
 * This class receives a page URL from the user then searches
 * the destination for potential thumbnails via Embedly.
 * The thumbnails (if found) are then returned as base64 in a JSON response.
 *
 * PHP version 5 (5.5.12)
 *
 * @author     Robert Gatzke
 * @copyright  2016 Robert Gatzke
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    1.0.0.0
 * @link       http://
 */

class Get_Thumbnails
{
	
	private $embedlyPrivateKey = '<HIDDEN - YOU CAN GET ONE FOR FREE FROM EMBED.LY>';
	
	private $debugInfoArray = 	array();
	private $imageFlavour; 		// jpeg | x-icon
	private $imagesToKeep;
	private $maxWidth;
	private $maxHeight;
	private $useDefaultThumb = 	'false';
	
	public function __construct($imagesToKeep = 3, $maxWidth = 70, $maxHeight = 55)
	{
		$this->imagesToKeep = 	$imagesToKeep;
		$this->maxWidth = 		$maxWidth;
		$this->maxHeight = 		$maxHeight;
	}
	
	// This function accepts a page URL.
	// Embedly uses this URL to find images on that page.
	// -(see: http://embed.ly/docs/api/extract)
	// Returns array of found image URLs.
	
	private function getImagesByPageUrl($pageURL)
	{
		$imageUrlArray =	array();
		$finalPageUrl = 	$this->cURL($pageURL)['finalPageUrl'];
		$curlResponse = 	$this->cURL('http://api.embed.ly/1/extract?key=' . 
							$this->embedlyPrivateKey . 
							'&url=' . urlencode($finalPageUrl));
		$responseCode = 	$curlResponse['responseCode'];
		$embedlyJSON = 		json_decode($curlResponse['embedlyJSON'], true);
		
		if ($responseCode == '200'){
			
			if (!empty($embedlyJSON['images'])){
				
				$this->imageFlavour = 'jpeg';
				
				$this->debugInfo("Embedly found " . count($embedlyJSON['images']) . " images.");
				
				if (count($embedlyJSON['images']) >= $this->imagesToKeep){
					$this->debugInfo("Keeping first " . $this->imagesToKeep . "..");
				}
				
				foreach($embedlyJSON['images'] as $embedlyImageURL){
					array_push($imageUrlArray, $embedlyImageURL['url']);
				}
				
			} else {
				
				$this->debugInfo("Embedly failed to find images.");
				$this->debugInfo("Searching for favicon..");
				
				if (!empty($embedlyJSON['favicon_url'])){
					
					$this->imageFlavour = 'x-icon';
					
					$this->debugInfo("Embedly found a favicon.");
					
					array_push($imageUrlArray, $embedlyJSON['favicon_url']);
					
				} else {
					$this->debugInfo("Embedly failed to find favicon..");
					$this->debugInfo("Default thumbnail will be used..");
					$this->useDefaultThumb = 'true';
				}
			}
				
			if (count($imageUrlArray) > $this->imagesToKeep){
				$imageUrlArray = array_slice($imageUrlArray, 0, $this->imagesToKeep, true);
			}
			
		} else {
			$this->debugInfo("Page response was \"" . $responseCode . "\" for \"" . $pageURL . "\"..");
			$this->useDefaultThumb = 'true';
		}
		
		return array(
			'responseCode'=>	$responseCode,
			'finalPageUrl'=>	$finalPageUrl,
			'imageUrlArray'=>	$imageUrlArray
		);
	}
	
	// This function creates thumbnails and returns them in base64.
	// Image URLs are sent to Embedly along with desired dimensions
	// for cropping. (see: http://embed.ly/docs/api/display/endpoints/1/display/crop)
	// Responses are base64 encoded before being returned to user via
	// JSON (Instead of using JavaScript which would reveal Embedly private key).
	
	private function getThumbnailImagesViaEmbedly($imageUrlArray)
	{
		$imageSourceBase64Array = array();
		
		if (!empty($imageUrlArray)){
			if ($this->imageFlavour == 'jpeg'){
				
				foreach($imageUrlArray as $selectedimageSource){
					
					$thumbnailedImageUrl = $this->cURL(
						'https://i.embed.ly/1/display/crop?key=' .
						$this->embedlyPrivateKey .
						'&url=' . urlencode($selectedimageSource) .
						'&height=' . $this->maxHeight .
						'&width=' . $this->maxWidth)['embedlyJSON'];
					
					array_push(
						$imageSourceBase64Array, 
						'data:image/' . 
						$this->imageFlavour . 
						';base64,' . 
						base64_encode($thumbnailedImageUrl)
					);
				}
				
			} elseif ($this->imageFlavour == 'x-icon'){
				
				// Thumbnails (x-icon) are not cropped, so no need to send back to Embedly first.
				
				array_push(
					$imageSourceBase64Array, 
					'data:image/' . 
					$this->imageFlavour . 
					';base64,' . 
					base64_encode($this->cURL($imageUrlArray[0])['embedlyJSON'])
				);
				
				// Make sure favicon remains square.
				$this->maxWidth = $this->maxHeight;
				
			} else {
				$this->debugInfo("No image flavour specified - No images were cropped..");
				$this->useDefaultThumb = 'true';
			}
		} else {
			$this->debugInfo("No URLs provided for cropping - No images were cropped..");
			$this->useDefaultThumb = 'true';
		}
		
		return $imageSourceBase64Array;
	}

	// Function accepts URL as parameter.
	// Returns array of:
	// -JSON from Embedly.
	// -Response code. (200, 404 etc.).
	// -Redirect URL (if supplied URL is redirected).
	
	private function cURL($pageURL)
	{
		$ch = curl_init($pageURL);
		
		// Set user agent to avoid "random" results.
		curl_setopt($ch, CURLOPT_USERAGENT,
			'Mozilla/5.0 (Windows NT 6.1; WOW64) ' . 
			'AppleWebKit/537.36 (KHTML, like Gecko) ' . 
			'Chrome/43.0.2357.134 Safari/537.36');
		// Return page as string only.
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Disabled to avoid some errors.
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// Follow all redirected URLs.
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		
		$embedlyJSON = 	curl_exec($ch);
		$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$finalPageUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

		curl_close($ch);
		
		return array(
			'embedlyJSON'=>		$embedlyJSON,
			'responseCode'=>	$responseCode,
			'finalPageUrl'=>	$finalPageUrl
		);
	}
	
	private function debugInfo($info)
	{
		array_push($this->debugInfoArray, $info);
	}
	
	//////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////
	
	// Final response to user request.
	public function createJsonResponse()
	{
		$finalPageUrl = 		"";
		$imageUrlArray = 		array();
		$thumbnailedImages = 	array();
		$width = 				"";
		$height = 				"";
		
		if (isset($_GET['pageUrl']) && 
			(!filter_var($_GET['pageUrl'], FILTER_VALIDATE_URL) === false)){
			
			$getImageInfo = 		$this->getImagesByPageUrl($_GET['pageUrl']);
			$finalPageUrl = 		$getImageInfo['finalPageUrl'];
			$imageUrlArray = 		$getImageInfo['imageUrlArray'];
			$thumbnailedImages = 	$this->getThumbnailImagesViaEmbedly($getImageInfo['imageUrlArray']);
			$width = 				$this->maxWidth;
			$height = 				$this->maxHeight;
			
		} else {
			$this->debugInfo("No initial URL or bad URL provided for image search. Please use http://");
			$this->useDefaultThumb = 'true';
		}
		
		echo json_encode(
				array(
					'useDefaultThumb'=>		$this->useDefaultThumb,
					'debugInfoArray'=>		$this->debugInfoArray,
					'finalPageUrl'=>		$finalPageUrl,
					'imageUrlArray'=>		$imageUrlArray,
					'thumbnailedImages'=>	$thumbnailedImages,
					'thumbnailDimensions'=>	array(
						'width'=>			$width,
						'height'=>			$height
					)
				)
		);
	}
	
	// THIS FUNCTION IS FOR DEMO ONLY!
	public function demo()
	{
		// STATIC URL FOR DEMO ONLY - replace for different results.
		$pageUrl = 'http://www.microsoft.com';
		
		if (isset($_GET['pageUrl']) && 
			!filter_var($_GET['pageUrl'], FILTER_VALIDATE_URL) === false){
				
			$pageUrl = $_GET['pageUrl'];
			
		} else {
			$this->debugInfo("No initial URL or bad URL provided for image search. Please use http://");
			$this->debugInfo("Using demo URL: " . $pageUrl);
		}
		
		$getImageInfo = 		$this->getImagesByPageUrl($pageUrl);
		$finalPageUrl = 		$getImageInfo['finalPageUrl'];
		$imageUrlArray = 		$getImageInfo['imageUrlArray'];
		$thumbnailedImages = 	$this->getThumbnailImagesViaEmbedly($getImageInfo['imageUrlArray']);
	
		echo '<pre>';
		
			echo '<u>The JSON array returned to the user:</u><br><br>';
			
			print_r(
				array(
					'useDefaultThumb'=>		$this->useDefaultThumb,
					'debugInfoArray'=>		$this->debugInfoArray,
					'finalPageUrl'=>		$finalPageUrl,
					'imageUrlArray'=>		$imageUrlArray,
					'thumbnailedImages'=>	$thumbnailedImages,
					'thumbnailDimensions'=>	array(
						'width'=>			$this->maxWidth,
						'height'=>			$this->maxHeight
					)
				)
			);
			
			echo '<br><u>Sample images:</u><br>';
			
			if (count($imageUrlArray) == 0){
				echo '<br>No images found.';
			} else {
				for($i=0; $i<count($imageUrlArray); $i++){
					echo '<br>Image ' . ($i+1) . ' before being cropped and thumbed:<br><br>';
					echo '<img src="' . $imageUrlArray[$i] . '" /><br>';
					echo '<br>Image ' . ($i+1) . ' after being cropped and thumbed:<br><br>';
					echo '<img src="' . $thumbnailedImages[$i] . '" /><br>';
				}
			}
			
		echo '</pre>';
		
	}
}

// $thumbnails = (new Get_Thumbnails())->createJsonResponse();

$thumbnails = (new Get_Thumbnails())->demo();

?>