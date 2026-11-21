<?php

namespace Bitbetter\Libvips;

use Exception;
use Kirby\Filesystem\F;
use Kirby\Image\Darkroom;
use Kirby\Image\Focus;
use Kirby\Image\Image;

/**
 * libvips Darkroom driver
 *
 * Generates thumbnails in-process via php-vips (FFI) or by
 * shelling out to the `vipsthumbnail` (and, for focus crops and
 * effects, the `vips`) CLI that ships with libvips.
 * Requires libvips >= 8.15.
 */
class Vips extends Darkroom
{
	/**
	 * Output formats that keep all frames of an animated image
	 */
	protected static array $animatableTypes = ['gif', 'webp'];

	/**
	 * Returns the default thumb settings,
	 * on top of Kirby's darkroom defaults
	 */
	protected function defaults(): array
	{
		return parent::defaults() + [
			// auto-rotate based on EXIF orientation (vips default)
			'autoOrient' => true,
			// 'ffi' (in-process php-vips), 'cli' (vipsthumbnail binary)
			// or 'auto' to use FFI whenever it is available
			'backend'    => 'auto',
			// path to the vipsthumbnail binary
			'bin'        => 'vipsthumbnail',
			// generate interlaced (progressive) JPEGs/PNGs
			'interlace'  => false,
			// ICC output profile ('srgb' recommended, false to disable)
			'profile'    => 'srgb',
			// content-aware cropping strategy for plain center crops:
			// false (deterministic center crop), 'attention' or 'entropy'
			'smartcrop'  => false,
			// strip all metadata from the thumbnail
			'strip'      => true,
			// number of threads vips may use
			'threads'    => 1,
			// path to the vips binary (used for exact focus crops)
			'vipsBin'    => 'vips',
		];
	}

	/**
	 * Creates and runs the vips command(s) to process the image
	 *
	 * @throws \Exception
	 */
	public function process(string $file, array $options = []): array
	{
		$options = $this->preprocess($file, $options);

		// generate into a temp file next to the target so a failed
		// run never leaves broken output at the final path
		$tmp   = $this->tmpRoot($file);
		$temps = [$tmp];

		try {
			$crop  = $options['crop'];
			$focus = $crop !== false ? Focus::coords(
				$crop,
				$options['sourceWidth'],
				$options['sourceHeight'],
				$options['width'],
				$options['height']
			) : null;

			$strategy = $focus !== null ? $this->smartcrop($crop, $options) : null;
			$animated = $this->isAnimated($file, $options);

			if ($this->backend($options) === 'ffi') {
				if ($animated === true) {
					$this->processFfiAnimated($file, $tmp, $focus, $strategy, $options);
				} else {
					$this->processFfi($file, $tmp, $focus, $strategy, $options);
				}
			} elseif (
				$animated === true &&
				$this->cliCanAnimate($focus, $options) === true
			) {
				$this->processCliAnimated($file, $tmp, $focus, $options, $temps);
			} else {
				$this->processCli($file, $tmp, $focus, $strategy, $options, $temps);
			}

			// move the finished thumb over the target file
			F::move($tmp, $file, true);
		} finally {
			foreach ($temps as $temp) {
				F::remove($temp);
			}
		}

		return $options;
	}

	/**
	 * Resolves which backend to use for this job
	 */
	protected function backend(array $options): string
	{
		return match ($options['backend']) {
			'ffi', 'cli' => $options['backend'],
			default      => static::ffiSupported() ? 'ffi' : 'cli'
		};
	}

	/**
	 * Checks once whether in-process php-vips (FFI) is usable
	 */
	public static function ffiSupported(): bool
	{
		static $supported = null;

		if ($supported === null) {
			try {
				$supported =
					class_exists(\Jcupitt\Vips\Config::class) === true &&
					is_string(\Jcupitt\Vips\Config::version()) === true;
			} catch (\Throwable) {
				$supported = false;
			}

			if ($supported === true) {
				static::ensureLocaleEnv();
			}
		}

		return $supported;
	}

	/**
	 * Works around a crash of in-process libvips in PHP-FPM on macOS:
	 * the first vips operation translates its description via gettext,
	 * and Homebrew's libintl asks the macOS preferences (CoreFoundation)
	 * for the user's language when no locale variable is set in the
	 * environment. PHP-FPM clears the environment (`clear_env`) and its
	 * workers are forked without exec, where CoreFoundation is unusable,
	 * so the worker dies with SIGSEGV. Any locale variable makes gettext
	 * skip that lookup; the PHP locale (setlocale) is not affected.
	 */
	protected static function ensureLocaleEnv(): void
	{
		if (PHP_OS_FAMILY !== 'Darwin') {
			return;
		}

		foreach (['LC_ALL', 'LC_MESSAGES', 'LANG', 'LANGUAGE'] as $name) {
			if (getenv($name) !== false && getenv($name) !== '') {
				return;
			}
		}

		putenv('LANG=C');
	}

	/**
	 * Generates the thumb via the vipsthumbnail/vips CLI binaries
	 *
	 * @throws \Exception
	 */
	protected function processCli(
		string $file,
		string $tmp,
		array|null $focus,
		string|null $strategy,
		array $options,
		array &$temps
	): void {
		$input = $file;

		if ($focus !== null && $strategy === null) {
			// exact crop rectangle (focal point or non-center position):
			// auto-rotate and crop first, then shrink the cropped region
			$input = $this->cropExact($file, $focus, $options, $temps);
		}

		$chain = $this->effects($options);

		if ($chain === []) {
			$this->exec($this->thumbnailCommand($input, $tmp, $options, $strategy));
			return;
		}

		// shrink into an uncompressed intermediate, run the
		// effects chain on it and encode in the very last step
		$temps[] = $work = $this->tmpRoot($file, 'v');
		$this->exec($this->thumbnailCommand($input, $work, $options, $strategy));
		$this->runChain($chain, $work, $tmp, $file, $options, $temps);
	}

	/**
	 * Generates an animated thumb via the CLI binaries by
	 * processing all frames as one tall strip
	 *
	 * @throws \Exception
	 */
	protected function processCliAnimated(
		string $file,
		string $tmp,
		array|null $focus,
		array $options,
		array &$temps
	): void {
		$input = $file . '[n=-1]';
		$chain = $this->effects($options);
		$trim  = $focus !== null && $focus['width'] < $options['sourceWidth'];

		if ($trim === false && $chain === []) {
			$this->exec($this->thumbnailCommand($input, $tmp, $options));
			return;
		}

		$temps[] = $work = $this->tmpRoot($file, 'v');

		if ($trim === false) {
			$this->exec($this->thumbnailCommand($input, $work, $options));
		} else {
			// shrink every frame to the thumb height, then trim the
			// left/right edges of the whole strip in a single crop
			$this->exec($this->thumbnailCommand($input, $work, ['width' => ''] + $options));

			[$stripWidth, $stripHeight] = $this->vipsSize($work);

			$scale = $options['height'] / $options['sourceHeight'];
			$left  = (int)round($focus['x1'] * $scale);
			$left  = max(0, min($stripWidth - $options['width'], $left));

			array_unshift($chain, ['crop', [
				(string)$left,
				'0',
				(string)min($options['width'], $stripWidth),
				(string)$stripHeight,
			]]);
		}

		$this->runChain($chain, $work, $tmp, $file, $options, $temps);
	}

	/**
	 * Checks if the CLI backend can keep the animation for this job:
	 * the vips CLI cannot crop each frame of a strip vertically, so
	 * crops that trim the top/bottom only get the first frame
	 */
	protected function cliCanAnimate(array|null $focus, array $options): bool
	{
		return $focus === null || $focus['height'] >= $options['sourceHeight'];
	}

	/**
	 * Reads width and height from the header of
	 * an uncompressed vips intermediate (.v) file
	 *
	 * @throws \Exception
	 */
	protected function vipsSize(string $file): array
	{
		$header = (string)file_get_contents($file, false, null, 0, 12);
		$format = match (substr($header, 0, 4)) {
			"\xb6\xa6\xf2\x08" => 'V2',
			"\x08\xf2\xa6\xb6" => 'N2',
			default            => throw new Exception('Invalid vips intermediate file: ' . $file)
		};

		return array_values(unpack($format, $header, 4));
	}

	/**
	 * Runs a chain of vips operations on the intermediate
	 * file and encodes the result in the very last step
	 *
	 * @throws \Exception
	 */
	protected function runChain(
		array $chain,
		string $work,
		string $tmp,
		string $file,
		array $options,
		array &$temps
	): void {
		$lastIndex = array_key_last($chain);

		foreach ($chain as $index => [$op, $args]) {
			if ($index === $lastIndex) {
				$save = $this->saveOptions(F::extension($tmp), $options);
				$dst  = $save === '' ? $tmp : $tmp . '[' . $save . ']';
			} else {
				$temps[] = $dst = $this->tmpRoot($file, 'v');
			}

			$this->exec($this->vipsCommand($op, $work, $dst, $args, $options));
			$work = $dst;
		}
	}

	/**
	 * Generates the thumb in-process via php-vips (FFI);
	 * the whole pipeline fuses in memory without any
	 * process spawns or intermediate files
	 */
	protected function processFfi(
		string $file,
		string $tmp,
		array|null $focus,
		string|null $strategy,
		array $options
	): void {
		$params = ['height' => $options['height']];

		if (is_string($options['profile']) === true) {
			$params['export-profile'] = $options['profile'];
		}

		if ($focus !== null && $strategy === null) {
			// exact crop rectangle: rotate upright, crop in display
			// space, then shrink the cropped region
			// the same thumb path can be generated again later in the
			// same PHP worker; never reuse a cached decode of the old file
			$image = \Jcupitt\Vips\Image::newFromFile($file, ['revalidate' => true]);

			if ($options['autoOrient'] === true) {
				$image = $image->autorot();
			}

			$image = $image
				->crop($focus['x1'], $focus['y1'], $focus['width'], $focus['height'])
				->thumbnail_image($options['width'], $params);
		} else {
			if ($strategy !== null) {
				$params['crop'] = $strategy;
			}

			if ($options['autoOrient'] === false) {
				$params['no-rotate'] = true;
			}

			$image = \Jcupitt\Vips\Image::thumbnail($file, $options['width'], $params);
		}

		$image = $this->ffiEffects($image, $options);
		$image->writeToFile($tmp, $this->ffiSaveParams(F::extension($tmp), $options));
	}

	/**
	 * Generates an animated thumb in-process via php-vips (FFI);
	 * every frame runs through the same crop, shrink and effects
	 */
	protected function processFfiAnimated(
		string $file,
		string $tmp,
		array|null $focus,
		string|null $strategy,
		array $options
	): void {
		// all frames stacked on top of each other
		$strip  = \Jcupitt\Vips\Image::newFromFile($file, ['n' => -1, 'revalidate' => true]);
		$height = $strip->getType('page-height') !== 0 ? $strip->get('page-height') : $strip->height;
		$pages  = max(1, intdiv($strip->height, $height));
		$params = ['height' => $options['height'], 'size' => 'force'];

		if (is_string($options['profile']) === true) {
			$params['export-profile'] = $options['profile'];
		}

		// content-aware crops shrink the frame until it just
		// covers the thumb and then cut the same window out of it
		$smart  = $strategy === 'attention' || $strategy === 'entropy';
		$scale  = max(
			$options['width'] / $options['sourceWidth'],
			$options['height'] / $options['sourceHeight']
		);
		$cover  = [
			'height' => max($options['height'], (int)round($options['sourceHeight'] * $scale)),
			'size'   => 'force'
		] + $params;
		$window = null;
		$frames = [];

		for ($page = 0; $page < $pages; $page++) {
			$frame = $strip->crop(0, $page * $height, $strip->width, $height);

			if ($focus !== null && $smart === true) {
				$frame = $frame->thumbnail_image(
					max($options['width'], (int)round($options['sourceWidth'] * $scale)),
					$cover
				);

				// find the most interesting region in the first
				// frame and reuse it so the animation stays steady
				if ($window === null) {
					$found  = $frame->smartcrop(
						$options['width'],
						$options['height'],
						['interesting' => $strategy]
					);
					$window = [-$found->xoffset, -$found->yoffset];
				}

				$frame = $frame->crop($window[0], $window[1], $options['width'], $options['height']);
			} else {
				if ($focus !== null) {
					$frame = $frame->crop($focus['x1'], $focus['y1'], $focus['width'], $focus['height']);
				}

				$frame = $frame->thumbnail_image($options['width'], $params);
			}

			$frames[] = $this->ffiEffects($frame, $options);
		}

		$image = \Jcupitt\Vips\Image::arrayjoin($frames, ['across' => 1])->copy();
		$image->set('page-height', $frames[0]->height);
		$image->writeToFile($tmp, $this->ffiSaveParams(F::extension($tmp), $options));
	}

	/**
	 * Applies the grayscale/blur/sharpen effects in-process
	 */
	protected function ffiEffects(
		\Jcupitt\Vips\Image $image,
		array $options
	): \Jcupitt\Vips\Image {
		if ($options['grayscale'] === true) {
			$image = $image->colourspace('b-w');
		}

		if ($options['blur'] !== false) {
			$image = $image->gaussblur((int)$options['blur']);
		}

		if (is_int($options['sharpen']) === true) {
			$amount = max(1, min(100, $options['sharpen'])) / 100;
			$image  = $image->sharpen(['sigma' => $amount]);
		}

		return $image;
	}

	/**
	 * Save parameters for php-vips' writeToFile,
	 * mirroring the CLI save option string
	 */
	protected function ffiSaveParams(string $extension, array $options): array
	{
		$extension = strtolower($extension);
		$params    = [];

		if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'tif', 'tiff'], true) === true) {
			$params['Q'] = $options['quality'];
		}

		if ($options['strip'] === true) {
			$params['keep'] = 'none';
		}

		if (
			$options['interlace'] === true &&
			in_array($extension, ['jpg', 'jpeg', 'png'], true) === true
		) {
			$params['interlace'] = true;
		}

		return $params;
	}

	/**
	 * Auto-rotates (if needed) and crops the source to the exact
	 * focus rectangle; returns the root of the cropped temp file
	 */
	protected function cropExact(
		string $file,
		array $focus,
		array $options,
		array &$temps
	): string {
		$input = $file;

		// EXIF orientation is applied by vipsthumbnail after cropping,
		// but the focus coordinates refer to the rotated image;
		// bake the rotation in first so the crop happens in display space
		if (
			$options['autoOrient'] === true &&
			$this->needsAutoRotation($file) === true
		) {
			$temps[] = $rotated = $this->tmpRoot($file, 'v');
			$this->exec($this->vipsCommand('autorot', $file, $rotated, [], $options));
			$input = $rotated;
		}

		$temps[] = $cropped = $this->tmpRoot($file, 'v');
		$this->exec($this->vipsCommand('crop', $input, $cropped, [
			(string)$focus['x1'],
			(string)$focus['y1'],
			(string)$focus['width'],
			(string)$focus['height'],
		], $options));

		return $cropped;
	}

	/**
	 * Builds a command for a single vips operation
	 */
	protected function vipsCommand(
		string $operation,
		string $src,
		string $dst,
		array $args,
		array $options
	): string {
		$command = sprintf(
			'%s %s %s %s',
			escapeshellarg($options['vipsBin']),
			escapeshellarg($operation),
			escapeshellarg($src),
			escapeshellarg($dst)
		);

		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		return $command . ' --vips-concurrency=' . escapeshellarg($options['threads']);
	}

	/**
	 * Returns the chain of vips operations for the
	 * requested blur/grayscale/sharpen effects
	 */
	protected function effects(array $options): array
	{
		$chain = [];

		if ($options['grayscale'] === true) {
			$chain[] = ['colourspace', ['b-w']];
		}

		if ($options['blur'] !== false) {
			// same sigma the ImageMagick driver uses (-blur 0xN)
			$chain[] = ['gaussblur', [(string)(int)$options['blur']]];
		}

		if (is_int($options['sharpen']) === true) {
			// match the ImageMagick driver: -sharpen 0x(amount/100)
			$amount  = max(1, min(100, $options['sharpen'])) / 100;
			$chain[] = ['sharpen', ['--sigma', (string)$amount]];
		}

		return $chain;
	}

	/**
	 * Runs a command and throws with the
	 * captured output if it failed
	 *
	 * @throws \Exception
	 */
	protected function exec(string $command): void
	{
		exec($command . ' 2>&1', $output, $return);

		if ($return !== 0) {
			throw new Exception(
				'The vips command could not be executed: ' . $command .
				' (' . trim(implode("\n", $output)) . ')'
			);
		}
	}

	/**
	 * Checks if the output format can keep
	 * all frames of an animated image
	 */
	protected function isAnimatable(string $file, array $options): bool
	{
		$format = $options['format'] ?? F::extension($file);
		return in_array(strtolower($format), static::$animatableTypes, true);
	}

	/**
	 * Checks if the job is an animation: an animated GIF/WebP
	 * source that is written to a format which keeps the frames
	 */
	protected function isAnimated(string $file, array $options): bool
	{
		if ($this->isAnimatable($file, $options) === false) {
			return false;
		}

		$handle = @fopen($file, 'rb');

		if ($handle === false) {
			return false;
		}

		try {
			$header = (string)fread($handle, 21);

			// animated WebPs set a flag in their `VP8X` header
			if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 8) === 'WEBPVP8X') {
				return (ord($header[20] ?? "\0") & 0x02) !== 0;
			}

			if (substr($header, 0, 3) !== 'GIF') {
				return false;
			}

			// animated GIFs have more than one
			// graphic control extension + image block
			$frames = 0;
			$buffer = $header;

			while ($frames < 2 && feof($handle) === false) {
				$buffer .= fread($handle, 1024 * 128);
				$frames += preg_match_all(
					'/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s',
					$buffer
				);
				// keep the tail in case a match spans two chunks
				// (one byte less than a full match to avoid double counts)
				$buffer = substr($buffer, -9);
			}

			return $frames > 1;
		} finally {
			fclose($handle);
		}
	}

	/**
	 * Checks if the source image carries an EXIF
	 * orientation that vips would auto-rotate
	 */
	protected function needsAutoRotation(string $file): bool
	{
		try {
			$orientation = (new Image($file))->exif()->orientation();
			return is_int($orientation) === true && $orientation > 1;
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * Builds the save option string for the output file
	 * depending on the target format
	 */
	protected function saveOptions(string $extension, array $options): string
	{
		$extension = strtolower($extension);
		$save      = [];

		// uncompressed intermediates don't take save options
		if ($extension === 'v') {
			return '';
		}

		// quality for formats with lossy (or quantized) encoders
		if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'tif', 'tiff'], true) === true) {
			$save[] = 'Q=' . $options['quality'];
		}

		if ($options['strip'] === true) {
			$save[] = 'keep=none';
		}

		if (
			$options['interlace'] === true &&
			in_array($extension, ['jpg', 'jpeg', 'png'], true) === true
		) {
			$save[] = 'interlace';
		}

		return implode(',', $save);
	}

	/**
	 * Returns the smartcrop strategy for a single-pass
	 * crop or null if an exact crop is required
	 */
	protected function smartcrop(mixed $crop, array $options): string|null
	{
		// explicit focal points ('42% 31%') always win over smartcrop
		if (is_string($crop) === true && Focus::isFocalPoint($crop) === true) {
			return null;
		}

		// content-aware cropping for plain center crops if configured
		if ($crop === 'center') {
			return match ($options['smartcrop']) {
				'attention' => 'attention',
				'entropy'   => 'entropy',
				default     => 'centre'
			};
		}

		// positional crops (top, bottom right, …) need an exact crop
		return null;
	}

	/**
	 * Builds the vipsthumbnail command
	 */
	protected function thumbnailCommand(
		string $src,
		string $dst,
		array $options,
		string|null $smartcrop = null
	): string {
		$command = sprintf(
			'%s %s --size %sx%s --vips-concurrency=%s',
			escapeshellarg($options['bin']),
			escapeshellarg($src),
			$options['width'],
			$options['height'],
			escapeshellarg($options['threads'])
		);

		if ($smartcrop !== null) {
			$command .= ' --smartcrop ' . escapeshellarg($smartcrop);
		}

		if ($options['autoOrient'] === false) {
			$command .= ' --no-rotate';
		}

		if (is_string($options['profile']) === true) {
			// `export-profile` is the name libvips < 8.17 knows; 8.17+ renamed it
			// to `output-profile` but still accepts the old one (CLI and C API)
			$command .= ' --export-profile ' . escapeshellarg($options['profile']);
		}

		$save = $this->saveOptions(F::extension($dst), $options);

		return $command . ' -o ' . escapeshellarg($save === '' ? $dst : $dst . '[' . $save . ']');
	}

	/**
	 * Returns a unique temp file root next to the given file
	 */
	protected function tmpRoot(string $file, string|null $extension = null): string
	{
		return F::dirname($file) . '/' .
			F::name($file) . '.vips-' . uniqid() . '.' .
			($extension ?? F::extension($file));
	}
}
