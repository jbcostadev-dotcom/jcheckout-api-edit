<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\HorsePayController;

class RefreshHorsePayTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horsepay:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renova tokens HorsePay para lojas com credenciais configuradas (executar a cada 6 horas)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rows = DB::table('pagamento_pix')
            ->where('logo_banco', 'horsePay')
            ->select('id_loja', 'chave', 'public_key')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('Nenhuma credencial HorsePay encontrada em pagamento_pix.');
            return 0;
        }

        $total = 0;
        $ok = 0;
        $fail = 0;

        $controller = new HorsePayController();

        foreach ($rows as $row) {
            $total++;
            if (empty($row->id_loja) || empty($row->chave) || empty($row->public_key)) {
                $this->warn('Loja ' . ($row->id_loja ?? 'N/A') . ' sem credenciais completas. Pulando.');
                $fail++;
                continue;
            }

            $token = $controller->createToken($row->id_loja);
            if ($token) {
                $ok++;
                $this->info('Token HorsePay atualizado para loja ' . $row->id_loja . '.');
            } else {
                $fail++;
                $this->error('Falha ao atualizar token para loja ' . $row->id_loja . '.');
            }
        }

        $this->info("HorsePay refresh concluído. Total: {$total}, OK: {$ok}, Falhas: {$fail}.");
        return 0;
    }
}