<?php
/**
 * @package     Joomla.Platform
 * @subpackage  Image
 *
 * @copyright   Copyright (C) 2005 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * Class to manipulate an image.
 *
 * @package     Joomla.Platform
 * @subpackage  Image
 * @since       11.3
 */
class modNSSP2ImageHelper extends JImage
{

	/**
	 * Class constructor.
	 *
	 * @param   mixed  $source  Either a file path for a source image or a GD resource handler for an image.
	 *
	 * @since   11.3
	 * @throws  RuntimeException
	 */
	public function __construct($source = null)
	{
		parent::__construct( $source );
	}

	/**
	 * Method to generate thumbnails from the current image. It allows
	 * creation by resizing or cropping the original image.
	 *
	 * @param   mixed    $thumbSizes      String or array of strings. Example: $thumbSizes = array('150x75','250x150');
	 * @param   integer  $creationMethod  1-3 resize $scaleMethod | 4 create croppping | 5 resize then crop
	 *
	 * @return  array
	 *
	 * @since   12.2
	 * @throws  LogicException
	 * @throws  InvalidArgumentException
	 */
	public function generateThumbs($thumbSizes, $creationMethod = self::SCALE_INSIDE)
	{
		// Make sure the resource handle is valid.
		if (!$this->isLoaded())
		{
			throw new LogicException('No valid image was loaded.');
		}

		// Accept a single thumbsize string as parameter
		if (!is_array($thumbSizes))
		{
			$thumbSizes = array($thumbSizes);
		}

		// Process thumbs
		$generated = array();

		if (!empty($thumbSizes))
		{
			foreach ($thumbSizes as $thumbSize)
			{
				// Desired thumbnail size
				$size = explode('x', strtolower($thumbSize));

				if (count($size) != 2)
				{
					throw new InvalidArgumentException('Invalid thumb size received: ' . $thumbSize);
				}

				$thumbWidth  = $size[0];
				$thumbHeight = $size[1];

				switch ($creationMethod)
				{
					// Case for self::CROP
					case 4:
						$thumb = $this->crop($thumbWidth, $thumbHeight, null, null, true);
						break;

					// Case for self::CROP_RESIZE
					case 5:
						$thumb = $this->cropResize($thumbWidth, $thumbHeight, true);
						break;

					default:
						$thumb = $this->resize($thumbWidth, $thumbHeight, true, $creationMethod);
						break;
				}

				// Store the thumb in the results array
				$generated[] = $thumb;
			}
		}

		return $generated;
	}

	/**
	 * Method to create thumbnails from the current image and save them to disk. It allows creation by resizing
	 * or croppping the original image.
	 *
	 * @param   mixed    $thumbSizes      string or array of strings. Example: $thumbSizes = array('150x75','250x150');
	 * @param   integer  $creationMethod  1-3 resize $scaleMethod | 4 create croppping
	 * @param   string   $thumbsFolder    destination thumbs folder. null generates a thumbs folder in the image folder
	 *
	 * @return  array
	 *
	 * @since   12.2
	 * @throws  LogicException
	 * @throws  InvalidArgumentException
	 */
	public function createThumbs($thumbSizes, $creationMethod = self::SCALE_INSIDE, $thumbsFolder = null)
	{
		// Make sure the resource handle is valid.
		if (!$this->isLoaded())
		{
			throw new LogicException('No valid image was loaded.');
		}

		// No thumbFolder set -> we will create a thumbs folder in the current image folder
		if (is_null($thumbsFolder))
		{
			$thumbsFolder = dirname($this->getPath()) . '/thumbs';
		}

		// Check destination
		if (!is_dir($thumbsFolder) && (!is_dir(dirname($thumbsFolder)) || !@mkdir($thumbsFolder)))
		{
			throw new InvalidArgumentException('Folder does not exist and cannot be created: ' . $thumbsFolder);
		}

		// Process thumbs
		$thumbsCreated = array();

		if ($thumbs = $this->generateThumbs($thumbSizes, $creationMethod))
		{
			// Parent image properties
			$imgProperties = self::getImageFileProperties($this->getPath());

			foreach ($thumbs as $thumb)
			{
				// Get thumb properties
				$thumbWidth     = $thumb->getWidth();
				$thumbHeight    = $thumb->getHeight();

				// Generate thumb name
				$filename       = pathinfo($this->getPath(), PATHINFO_FILENAME);
				$fileExtension  = pathinfo($this->getPath(), PATHINFO_EXTENSION);
				$thumbFileName  = $filename . '_' . $thumbWidth . 'x' . $thumbHeight . '.' . $fileExtension;

				// Save thumb file to disk
				$thumbFileName = $thumbsFolder . '/' . $thumbFileName;

				if ($thumb->toFile($thumbFileName, $imgProperties->type))
				{
					// Return JImage object with thumb path to ease further manipulation
					$thumb->path = $thumbFileName;
					$thumbsCreated[] = $thumb;
				}
			}
		}

		return $thumbsCreated;
	}

	/**
	 * Method to crop an image after resizing it to maintain
	 * proportions without having to do all the set up work.
	 *
	 * @param   integer  $width      The desired width of the image in pixels or a percentage.
	 * @param   integer  $height     The desired height of the image in pixels or a percentage.
	 * @param   integer  $createNew  If true the current image will be cloned, resized, cropped and returned.
	 *
	 * @return  object  JImage Object for chaining.
	 *
	 * @since   12.3
	 */
	public function cropResize($width, $height, $createNew = true)
	{
		$width   = $this->sanitizeWidth($width, $height);
		$height  = $this->sanitizeHeight($height, $width);

		if (($this->getWidth() / $width) < ($this->getHeight() / $height))
		{
			$this->resize($width, 0, false);
		}
		else
		{
			$this->resize(0, $height, false);
		}

		return $this->crop($width, $height, null, null, $createNew);
	}

	/**
	 * Method to destroy an image handle and
	 * free the memory associated with the handle
	 *
	 * @return  boolean  True on success, false on failure or if no image is loaded
	 *
	 * @since   12.3
	 */
	public function destroy()
	{
		if ($this->isLoaded())
		{
			return imagedestroy($this->handle);
		}

		return false;
	}

	/**
	 * Method to call the destroy() method one last time
	 * to free any memory when the object is unset
	 *
	 * @see     JImage::destroy()
	 * @since   12.3
	 */
	public function __destruct()
	{
		$this->destroy();
	}
}
