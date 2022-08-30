<?php

namespace Techquity\Aero\Sage\Console\Commands;

use Illuminate\Console\Command;
use Techquity\Aero\Sage\Jobs\UpdateProduct;

class UpdateProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sage:update-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update products from Sage';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        UpdateProduct::dispatch()->onQueue('sage_50_import');
    }
}
