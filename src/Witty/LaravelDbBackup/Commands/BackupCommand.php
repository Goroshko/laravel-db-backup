<?php 

namespace Witty\LaravelDbBackup\Commands;

use Mail;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;


use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Witty\LaravelDbBackup\Commands\Helpers\BackupFile;
use Witty\LaravelDbBackup\Commands\Helpers\BackupHandler;

use App\Helpers\Classes\S3;

class BackupCommand extends BaseCommand 
{
	/**
	 * @var string
	 */
	protected $name = 'db:backup';
	protected $description = 'Backup the default database to `storage/dumps`';
	protected $filePath;
	protected $fileName;

	/**
	 * @return void
	 */
	public function fire()
	{
		$database = $this->getDatabase($this->input->getOption('database'));

		$this->checkDumpFolder();

		//----------------
		$dbfile = new BackupFile( $this->argument('filename'), $database, $this->getDumpsPath() );
		$this->filePath = $dbfile->path();
		$this->fileName = $dbfile->name();

		$status = $database->dump($this->filePath);
		$handler = new BackupHandler( $this->colors );

		// Error
		//----------------
		if ($status !== true)
		{
			return $this->line( $handler->errorResponse( $status ) );
		}

		// Compression
		//----------------
		if ($this->isCompressionEnabled())
		{
			$this->compress();
			$this->fileName .= ".gz";
			$this->filePath .= ".gz";
		}

		$this->line( $handler->dumpResponse( $this->argument('filename'), $this->filePath, $this->fileName ) );

		// S3 Upload
		//----------------
		if ($this->option('upload-s3'))
		{
			$this->uploadS3();
			$this->line( $handler->s3DumpResponse() );

			if ($this->option('keep-only-s3'))
			{
				File::delete($this->filePath);
				$this->line( $handler->localDumpRemovedResponse() );
			}
		}
	}

	/**
	 * Perform Gzip compression on file
	 * 
	 * @return boolean
	 */ 
	protected function compress()
	{
		$command = sprintf('gzip -9 %s', $this->filePath);

		return $this->console->run($command);
	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['filename', InputArgument::OPTIONAL, 'Filename or -path for the dump.'],
		];
	}

	/**
	 * @return array
	 */
	protected function getOptions()
	{
		return [
			['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to backup'],
			['upload-s3', 'u', InputOption::VALUE_REQUIRED, 'Upload the dump to your S3 bucket'],
			['keep-only-s3', true, InputOption::VALUE_NONE, 'Delete the local dump after upload to S3 bucket']
		];
	}

	/**
	 * @return void
	 */
	protected function checkDumpFolder()
	{
		$dumpsPath = $this->getDumpsPath();

		if ( ! is_dir($dumpsPath))
		{
			mkdir($dumpsPath);
		}
	}

	/**
	 * @return void
	 */
	protected function uploadS3()
	{
        $s3_client = new S3Client([
						'credentials' => [
								'key'    => Config::get('db-backup.s3.key'),
								'secret' => Config::get('db-backup.s3.secret')
							],
							'region' => Config::get('db-backup.s3.region'),
							'version' => 'latest',
						]);

       	$s3_bucket = Config::get('db-backup.s3.bucket');
       	$s3_adapter = new AwsS3Adapter($s3_client, $s3_bucket);
       	$s3_filesystem = new Filesystem($s3_adapter);

       	$s3_filesystem->put($this->fileName, file_get_contents($this->filePath));

       	$domain = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'].' - ' : '';
	    Mail::raw('db:backup error', function($message) use ($domain){
	    	$message->to( Config::get('db-backup.mail.to') )->subject($domain.'db:backup error!');;
	    });
	}

	/**
	 * @return string
	 */
	protected function getS3DumpsPath()
	{
		$default = 'dumps';

		return Config::get('db-backup.s3.path', $default);
	}
}
