<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Exceptions\DataNotFoundException;
use App\Exceptions\InvalidRuleException;
use App\Exceptions\ParameterException;
use App\Exceptions\ThirdPartyServiceException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\DataCommentCra;
use App\Repositories\PrakarsaMediumRepository;
use App\Collections\DataCommentCraCollection;
use App\Collections\AnalisaTambahanCollection;
use App\Collections\ApprovalCollection;
use App\Collections\DataCrsMenengahCollection;
use App\Collections\GroupDebiturCollection;
use App\Collections\JawabanQcaCraCollection;
use App\Collections\PengurusCollection;
use App\Collections\PesertaPrakomiteMediumCollection;
use App\Collections\ReviewDokumentasiKreditCollection;
use App\Collections\FasilitasPinjamanCollection;
use App\Collections\FasilitasLainnyaCollection;
use App\Facades\PrakarsaFacade;
use App\Repositories\PrakarsaRepository;
use Carbon\Carbon;
use stdClass;
use App\Library\RestClient;
use App\Models\DataCrsMenengah;
use App\Models\DataLainnya;
use App\Models\DataPengajuanMenengah;
use App\Models\Prakarsa;
use App\Models\DataNonFinansialMenengah;
use Jobcloud\Kafka\Message\KafkaProducerMessage;
use App\Library\Kafka;
use App\Models\CppCDCPoP;
use App\Models\PrakarsaMedium;
use Illuminate\Support\Facades\Log;
use Google\Cloud\Storage\Connection\Rest;
use Prophecy\Call\Call;
use App\Library\MonolithicClient;
use App\Models\DataFinansialMenengah;
use App\Jobs\generateFormAgunanJob;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use App\Models\DataTempatUsahaRitel;
use App\Jobs\generateMABMenengahJob;
use Illuminate\Support\Facades\Queue;

class PrakarsaMediumController extends Controller
{
    private $prakarsamediumRepo;
    private $prakarsa_repo;
    private $output;
    private $resClient;

    public function __construct(PrakarsaMediumRepository $prakarsamediumRepo,  PrakarsaRepository $prakarsa_repo, RestClient $resClient)
    {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        $this->prakarsamediumRepo = $prakarsamediumRepo;
        $this->prakarsa_repo = $prakarsa_repo;
        $this->resClient = $resClient;

        $this->output = new stdClass();
        $this->output->responseCode = '';
        $this->output->responseDesc = '';
    }

    public function inquiryListPrakarsaMedium(Request $request)
    {
        if (isset($request->page) && !is_int($request->page)) {
            throw new ParameterException("Parameter page harus integer");
        }
        
        if ((isset($request->tp_produk) || (isset($request->tp_produk_in) && !empty($request->tp_produk_in) && !is_array($request->tp_produk_in))) && !in_array($request->tp_produk, ['49', '50'])) {
            throw new ParameterException("Parameter tp_produk atau tp_produk_in harus segmen menengah / upper small");
        }

        $pemutus = [];
        if (isset($request->pemutus) && !empty($request->pemutus)) {
            array_push($pemutus, ["pn" => $request->pemutus,"tipe" => "pemutus","flag_putusan" => ""]);
        }
        if (isset($request->pemrakarsa_tambahan) && !empty($request->pemrakarsa_tambahan)) {
            array_push($pemutus, ["pn" => $request->pemrakarsa_tambahan,"tipe" => "pemrakarsa_tambahan","flag_putusan" => ""]);
        }

        $filter = [
            'refno' => isset($request->refno) ? $request->refno : null,
            'branch' => isset($request->branch) ? $request->branch : null,
            'branch_in' => isset($request->branch_in) ? $request->branch_in : null,
            'cif' => isset($request->cif) ? $request->cif : null,
            'tp_produk_in' => isset($request->tp_produk_in) ? $request->tp_produk_in : ['49', '50'],
            'id_aplikasi' => isset($request->id_aplikasi) ? $request->id_aplikasi : null,
            'status' => isset($request->status) ? $request->status : null,
            'status_in' => isset($request->status_in) ? $request->status_in : null,
            'not_in_status' => isset($request->not_in_status) ? $request->not_in_status : null,
            'nik' => isset($request->nik) ? $request->nik : null,
            'segmen' => isset($request->segmen) ? $request->segmen : null,
            'nama_debitur' => isset($request->nama_debitur) ? $request->nama_debitur : null,
            'region' => isset($request->region) ? $request->region : null,
            'kode_uker_bo' => isset($request->kode_uker_bo) ? $request->kode_uker_bo : null,
            'pemutus_arr' => !empty($pemutus) ? $pemutus : null,
            'pemutus' => !empty($request->pemutus) ? $request->pemutus : null,
            'pemrakarsa_tambahan' => !empty($request->pemrakarsa_tambahan) ? $request->pemrakarsa_tambahan : null,
            'page' => isset($request->page) ? $request->page : 1,
        ];

        $get_list_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        if ($get_list_prakarsa->isEmpty()) {
            throw new DataNotFoundException("Inquiry list prakarsa not found");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses inquiry list prakarsa';
        $this->output->responseData = $get_list_prakarsa;

        return response()->json($this->output);
    }

    public function inquiryPrakarsaMedium(Request $request)
    {
        if (empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak boleh kosong");
        }
        if (isset($request->content_data) && !is_array($request->content_data)) {
            throw new ParameterException("Parameter content_data harus array");
        }

        $content_data = isset($request->content_data) && is_array($request->content_data) ? $request->content_data : [];

        $get_prakarsa = $this->prakarsamediumRepo->getPrakarsa($request->refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Inquiry prakarsa not found");
        }

        if (in_array('content_datarekomendasi', $content_data) && PrakarsaFacade::checkJSON($get_prakarsa->content_datarekomendasi)) {
            $get_prakarsa->content_datarekomendasi = json_decode($get_prakarsa->content_datarekomendasi);
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses inquiry prakarsa';
        $this->output->responseData = $get_prakarsa;

        return response()->json($this->output);
    }

    public function inquiryPengelolaanKeuanganMedium(Request $request)
    {
        if (empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak boleh kosong");
        }
        if (isset($request->content_data) && !is_array($request->content_data)) {
            throw new ParameterException("Parameter content_data harus array");
        }

        $filter = [
            'refno' => $request->refno,
            'content_data' => isset($request->content_data) && is_array($request->content_data) ? $request->content_data : []
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        if (empty($pengelolaan_keuangan->toArray())) {
            throw new DataNotFoundException("Data pengelolaan keuangan tidak ditemukan");
        }

        $object_data = $this->autoDecodeContentData($pengelolaan_keuangan->toArray());

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses inquiry pengelolaan keuangan';
        $this->output->responseData = $object_data;

        return response()->json($this->output);
    }

    function autoDecodeContentData($data) {
        $json_encode_list = [
            'content_data_labarugi',
            'content_data_neraca',
            'content_ratio_keuangan',
            'content_data_asumsi',
            'content_data_prospek_industri',
            'content_data_kas',
            'content_data_kebutuhan_kredit',
            'content_data_char_cap',
            'content_data_checklistdokumen',
            'content_data_prescreening',
            'content_data_polaangsuran',
            'content_data_rekomendasi',
            'content_data_crr',
            'content_data_badanusaha',
            'content_syarat_perjanjian',
            'content_data_lainnya2'
        ];

        $result = new stdClass();
        foreach ($data as $key_data => $row_data) {
            if (in_array($key_data, $json_encode_list) && PrakarsaFacade::checkJSON($row_data)) {
                $result->$key_data = json_decode($row_data);
            }
        }
        return $result;
    }

    function cek_JSON($string) {
        return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }

    public function insertPengurusPenjaminDanDebitur(Request $request, RestClient $client)
    {
        if (empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (empty($request->user)) {
            throw new ParameterException("Parameter user tidak valid");
        }

        if (empty($request->branch)) {
            throw new ParameterException("Parameter branch tidak valid");
        }

        if (isset($request->data_pengurus) && !is_array($request->data_pengurus)) {
            throw new ParameterException("Paramater data_pengurus tidak valid");
        }

        if (isset($request->group_debitur) && !is_array($request->group_debitur)) {
            throw new ParameterException("Paramater group_debitur tidak valid");
        }

        $content_data = ['content_datapribadi'];

        $prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);
        if (empty($prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        if ($prakarsa->cif_las == "0") {
            throw new InvalidRuleException("Mohon melakukan pengiriman data pribadi");
        }

        if ($prakarsa->status != "1") {
            throw new InvalidRuleException("Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya");
        }

        $content_datapribadi = $prakarsa->content_datapribadi;

        $request_las = new stdClass();
        $request_las->cif_las        = $prakarsa->cif_las;
        $request_las->data_pengurus  = $request->data_pengurus;

        // insert data pengurus penjamin
        if (isset($content_datapribadi->jenis_kelamin) && $content_datapribadi->jenis_kelamin == "") {
            $clientResponse = $client->call($request_las, env('BRISPOT_EKSTERNAL_URL'), '/las/insertDataPengurusPenjamin');

            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new InvalidRuleException($clientResponse->responseDesc);
            }
        }

        $content_datapribadi->group_debitur = new GroupDebiturCollection($request->group_debitur);
        $content_datapribadi->pengurus      = new PengurusCollection($request->data_pengurus);

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data';

        return response()->json($this->output);
    }

    public function inquiryListDisposisiCRA(Request $request)
    {
        $list_disposisi = [];
        if (empty($request->region)) {
            throw new ParameterException("Parameter region tidak valid");
        }
        if (empty($request->limit) || !isset($request->limit) || !is_int($request->limit)) {
            throw new ParameterException("Parameter limit tidak valid");
        }
        if (empty($request->page) || !isset($request->page) || !is_int($request->page)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        $nama_debitur   = isset($request->nama_debitur) && $request->nama_debitur != "" ? $request->nama_debitur : '';
        $branch         = isset($request->branch) && $request->branch != "" ? $request->branch : '';
        $pn_rm          = isset($request->pn_rm) && $request->pn_rm != "" ? $request->pn_rm : '';
        $status_disposisi          = isset($request->status_disposisi) && $request->status_disposisi != "" ? $request->status_disposisi : '';

        $filter = [
            'region'        => $request->region,
            'nama_debitur'  => $nama_debitur,
            'branch'        => $branch,
            'userid_ao'     => $pn_rm,
            'not_in_status' => ["0", "5", "4", "105"],
            'status_cra'    => $status_disposisi,
            'tp_produk_in'  => ["49", "50"],
            'limit'         => $request->limit,
            'page'          => $request->page,
            'content_data'  => ['content_datapengajuan', 'content_datafinansial']
        ];

        $list_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        if (empty($list_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        foreach ($list_prakarsa as $prakarsa) {
            $plafon = $khtpk = "0";
            $content_datapengajuan = $prakarsa->content_datapengajuan != "" && !is_array($prakarsa->content_datapengajuan) ? json_decode($prakarsa->content_datapengajuan, true) : $prakarsa->content_datapengajuan;
            $content_datafinansial = $prakarsa->content_datafinansial != "" && !is_array($prakarsa->content_datafinansial) ? json_decode($prakarsa->content_datafinansial, true) : $prakarsa->content_datafinansial;

            if ($content_datapengajuan != "") {
                $plafon = isset($content_datapengajuan['amount']) ? $content_datapengajuan['amount'] : "0";
            }
            if ($content_datafinansial != "") {
                $khtpk = isset($content_datafinansial['total_exposure_khtpk']) ? $content_datafinansial['total_exposure_khtpk'] : "0";
            }

            $disposisi                  = new stdClass();
            $disposisi->nik             = $prakarsa->nik;
            $disposisi->refno           = $prakarsa->refno;
            $disposisi->nama_debitur    = $prakarsa->nama_debitur;
            $disposisi->userid_ao       = $prakarsa->pn_pemrakarsa;
            $disposisi->nama_ao         = $prakarsa->nama_pemrakarsa;
            $disposisi->branch          = $prakarsa->branch;
            $disposisi->nama_branch     = $prakarsa->nama_branch;
            $disposisi->jenis_pinjaman  = $prakarsa->jenis_pinjaman;
            $disposisi->total_plafond   = $plafon;
            $disposisi->total_exposure  = $khtpk;
            $disposisi->pn_cra          = $prakarsa->pn_cra;
            $disposisi->nama_cra        = $prakarsa->nama_cra;

            array_push($list_disposisi, $disposisi);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry data';
        $this->output->responseData = $list_disposisi;

        return response()->json($this->output);
    }

    public function insertDisposisiCRA(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->pn_cra) || !isset($request->pn_cra)) {
            throw new ParameterException("Parameter pn_cra tidak valid");
        }
        if (empty($request->nama_cra) || !isset($request->nama_cra)) {
            throw new ParameterException("Parameter nama_cra tidak valid");
        }

        $data = [
            'refno'     => $request->refno,
            'pn_cra'    => $request->pn_cra,
            'nama_cra'  => $request->nama_cra
        ];

        $new_prakarsa = new Prakarsa($data);

        $disposisi = $this->prakarsa_repo->updatePrakarsa($new_prakarsa);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses disposisi CRA';

        return response()->json($this->output);
    }

    public function insertKomentarCRA(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (empty($request->nama) || !isset($request->nama)) {
            throw new ParameterException("Parameter nama tidak valid");
        }
        if (empty($request->jabatan) || !isset($request->jabatan)) {
            throw new ParameterException("Parameter jabatan tidak valid");
        }
        if (empty($request->jenis) || !isset($request->jenis)) {
            throw new ParameterException("Parameter jenis tidak valid");
        }

        $new_content_data_comment_cra = [];
        $catatan = new DataCommentCra();

        $catatan->pn                = $request->pn;
        $catatan->nama              = $request->nama;
        $catatan->jabatan           = $request->jabatan;
        $catatan->jenis             = $request->jenis;
        $catatan->flag_sependapat   = $request->flag_sependapat;
        $catatan->catatan           = $request->catatan;
        $catatan->created_at        = Carbon::now()->toDateTimeString();
        $catatan->updated_at        = Carbon::now()->toDateTimeString();

        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_analisa_tambahan', 'content_data_comment_cra']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        if (empty($pengelolaan_keuangan->toArray())) {
            throw new DataNotFoundException("Data pengelolaan keuangan tidak ditemukan");
        }

        $prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        if (!in_array($prakarsa->status, [32])) {
            throw new InvalidRuleException("Belum dapat mengisi komentar");
        }

        $content_data_comment_cra = !is_array($pengelolaan_keuangan['content_data_comment_cra']) && isset($pengelolaan_keuangan['content_data_comment_cra']) ? $pengelolaan_keuangan['content_data_comment_cra'] : [];

        $push = true;
        if (!empty($content_data_comment_cra)) {
            foreach ($content_data_comment_cra as $komen) {
                if ($komen->jabatan == $catatan->jabatan && $catatan->jenis == $komen->jenis) {
                    $push = false;
                    $catatan->created_at = $komen->created_at;
                    array_push($new_content_data_comment_cra, $catatan);
                } else {
                    $comment                = new DataCommentCra();
                    $comment->pn            = $komen->pn;
                    $comment->nama          = $komen->nama;
                    $comment->jabatan       = $komen->jabatan;
                    $comment->jenis         = $komen->jenis;
                    $comment->flag_sependapat  = $komen->flag_sependapat;
                    $comment->catatan        = $komen->catatan;
                    $comment->created_at     = $komen->created_at;
                    $comment->updated_at     = $komen->updated_at;

                    array_push($new_content_data_comment_cra, $comment);
                }
            }
        }
        if ($push) {
            array_push($new_content_data_comment_cra, $catatan);
        }

        $content_comment_collection = new DataCommentCraCollection($new_content_data_comment_cra);
        $pengelolaan_keuangan['content_data_comment_cra'] = $content_comment_collection->toJson();

        $this->prakarsamediumRepo->updatePengelolaanKeuangan($pengelolaan_keuangan);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil simpan komentar';

        return response()->json($this->output);
    }

    public function inquiryKomentarCRA(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->jenis) || !isset($request->jenis) || !is_array($request->jenis)) {
            throw new ParameterException("Parameter jenis tidak valid");
        }

        $jabatan = isset($request->jabatan) && !empty($request->jabatan) ? $request->jabatan : null;
        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_data_comment_cra']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        if (empty($pengelolaan_keuangan->toArray())) {
            throw new DataNotFoundException("Data pengelolaan keuangan tidak ditemukan");
        }

        $list_komen = [];
        $content_data_comment_cra = !is_array($pengelolaan_keuangan['content_data_comment_cra']) && isset($pengelolaan_keuangan['content_data_comment_cra']) ? $pengelolaan_keuangan['content_data_comment_cra'] : [];

        foreach ($content_data_comment_cra as $komen) {
            if ($jabatan != null && $komen->jabatan == $request->jabatan && in_array($komen->jenis, $request->jenis)) {
                array_push($list_komen, $komen);
            } else if (in_array($komen->jenis, $request->jenis) && empty($jabatan)) {
                array_push($list_komen, $komen);
            }
        }

        if (empty($list_komen)) {
            throw new DataNotFoundException("Komentar tidak ditemukan");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry komentar';
        $this->output->responseData = $list_komen;

        return response()->json($this->output);
    }

    public function inquiryListPengajuanKredit(Request $request)
    {
        if (empty($request->pn_cra) || !isset($request->pn_cra)) {
            throw new ParameterException("Parameter pn_cra tidak valid");
        }
        if (empty($request->page) || !isset($request->page)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        if (empty($request->limit) || !isset($request->limit)) {
            throw new ParameterException("Parameter limit tidak valid");
        }

        $tp_produk = isset($request->tp_produk) && !empty($request->tp_produk) ? [$request->tp_produk] : ["49", "50"];

        $filter = [
            'userid_ao'     => isset($request->userid_ao) ? $request->userid_ao : '',
            'nama_debitur'  => isset($request->nama_debitur) ? $request->nama_debitur : '',
            'branch'        => isset($request->branch) ? $request->branch : '',
            'not_in_status' => ["0", "5", "33"],
            'pn_cra'        => $request->pn_cra,
            'tp_produk_in'  => $tp_produk,
            'limit'         => $request->limit,
            'page'          => $request->page,
            'status'        => isset($request->status) ? $request->status : '',
            'status_in'     => isset($request->status_in) ? $request->status_in : '',
        ];

        $list_prakarsa = $list_produk = $list_status = [];
        $get_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        $get_produk = $this->prakarsamediumRepo->getProdukBySegment($segment = 'sme');
        if (!empty($get_produk->toArray())) {
            foreach ($get_produk as $produk) {
                $list_produk[$produk->tp_produk] = $produk->nama_produk;
            }
        }

        $get_status = $this->prakarsamediumRepo->getStatus();
        if (!empty($get_status->toArray())) {
            foreach ($get_status as $status) {
                $list_status[$status->id] = $status->keterangan;
            }
        }

        foreach ($get_prakarsa->toArray() as $v_prakarsa) {
            $tp_produk = in_array($v_prakarsa->tp_produk, array_keys($list_produk)) ? $list_produk[$v_prakarsa->tp_produk] : "-";
            $v_status = in_array($v_prakarsa->status, array_keys($list_status)) ? $list_status[$v_prakarsa->status] : "-";

            if ($v_prakarsa->tp_produk == "49" && $v_prakarsa->status == "31") {
                $v_status = "Review Pinca";
            } else if ($v_prakarsa->tp_produk == "50" && $v_prakarsa->status == "31") {
                $v_status = "Review Dept Head";
            }

            $prakarsa = new stdClass();
            $prakarsa->refno        = $v_prakarsa->refno;
            $prakarsa->nama_debitur = $v_prakarsa->nama_debitur;
            $prakarsa->userid_ao    = $v_prakarsa->userid_ao;
            $prakarsa->nama_ao      = $v_prakarsa->nama_ao;
            $prakarsa->branch       = $v_prakarsa->branch;
            $prakarsa->nama_branch  = $v_prakarsa->nama_branch;
            $prakarsa->produk       = $tp_produk;
            $prakarsa->status       = $v_prakarsa->status;
            $prakarsa->status       = $v_status;

            array_push($list_prakarsa, $prakarsa);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry pengajuan kredit';
        $this->output->responseData = $list_prakarsa;

        return response()->json($this->output);
    }

    public function insertAnalisaTambahan(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->analisa_tambahan) || !isset($request->analisa_tambahan) || !is_array($request->analisa_tambahan)) {
            throw new ParameterException("Parameter analisa_tambahan tidak valid");
        }
        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_analisa_tambahan']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);

        $analisa_tambahan_collection = new AnalisaTambahanCollection($request->analisa_tambahan);

        $pengelolaan_keuangan_item = empty($pengelolaan_keuangan->toArray()) ? true : false;

        $pengelolaan_keuangan['refno'] = $request->refno;
        $pengelolaan_keuangan['content_analisa_tambahan'] = $analisa_tambahan_collection->toJson();
        if ($pengelolaan_keuangan_item) {
            $this->prakarsamediumRepo->insertPengelolaanKeuangan($pengelolaan_keuangan);
        }

        $this->prakarsamediumRepo->updatePengelolaanKeuangan($pengelolaan_keuangan);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data';

        return response()->json($this->output);
    }

    public function inquiryListAnalisaTambahan(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        $path_file = !empty($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;
        $path_folder = !empty($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : NULL;

        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_analisa_tambahan']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        if (empty($pengelolaan_keuangan->toArray())) {
            throw new DataNotFoundException("Data pengelolaan keuangan tidak ditemukan");
        }

        $analisa_tambahan = $pengelolaan_keuangan->content_analisa_tambahan;
        if (empty($analisa_tambahan) || $analisa_tambahan == "") {
            throw new DataNotFoundException("Data analisa tambahan tidak ditemukan");
        }

        $hash_link = Str::random(40);
        if (!is_dir($path_folder)) {
            mkdir($path_folder, 0775, true);
        }
        $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public') . '/securelink/' . $hash_link;
        exec($symlink_url);
        $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link;

        foreach ($analisa_tambahan as $value) {
            if (isset($value['file_analisa']) && $value['file_analisa'] != "") {
                $value['file_analisa'] = $url_file . '/analisa_tambahan/' . $value['file_analisa'];
            }
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry Analisa Tambahan';
        $this->output->responseData = $analisa_tambahan;

        return response()->json($this->output);
    }

    public function kirimReviewerMenengah(Request $request, RestClient $client)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (empty($request->status) || !isset($request->status)) {
            throw new ParameterException("Parameter status tidak valid");
        }

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, [49, 50])) {
            throw new InvalidRuleException("Refno ini bukan prakarsa menengah");
        }

        $current_status = $get_prakarsa->status;
        $new_status = $request->status;

        if (in_array($current_status, ["30", "31"]) && $new_status == "1") {
            throw new InvalidRuleException("Prakarsa tidak dapat kembali ke RM");
        }

        $approval = !empty($get_prakarsa->approval) ? json_decode($get_prakarsa->approval->toJson(), true) : [];

        if (!empty($approval)) {
            $filteredApproval = array_filter($approval, function ($value) use ($request) {
                return isset($value['pn_pemutus']) && $value['pn_pemutus'] !== $request->pn;
            });

            $approval = $filteredApproval;
        }

        if (in_array($new_status, ["33","34","35","36"])) {
            $data_approval = [];
            $data_approval['pn_pemutus'] = $request->pn;
            $data_approval['nama_pemutus'] = $request->nama_pemutus;
            $data_approval['jabatan_pemutus'] = $request->jabatan_pemutus;
            $data_approval['tgl_putusan'] = Carbon::now()->format('Y-m-d H:i:s');
            $data_approval['catatan_pemutus'] = $request->catatan_pemutus;

            $approval[] = $data_approval;
        } 

        if (($get_prakarsa->tp_produk == '49' && $current_status == "30" && $new_status == "31") || ($get_prakarsa->tp_produk == '50' && $current_status == "31" && $new_status == "32")) {
            $data_approval = new stdClass;

            if ($get_prakarsa->tp_produk == '49' && $current_status == "30" && $new_status == "31") {
                $param = new stdClass();
                $param->branch = str_pad($get_prakarsa->branch, 5, '0', STR_PAD_LEFT);
                $inquirySbhLas = $client->call($param, env('BRISPOT_CORE_LAS_URL'), '/v1/inquiryMappingSBHByBranch');

                if (isset($inquirySbhLas->responseCode) && $inquirySbhLas->responseCode != "00") {
                    $param = new stdClass();
                    $param->hilfm       = "014";
                    $param->branch_in   = [$get_prakarsa->branch];
                    $param->include_pgs = true;
                    $param->page        = 1;
                    $clientResponse     = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryListPekerja');

                    if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00" && empty($clientResponse->responseData)) {
                        $new_status = "32";
                    }
                }
            }

            $data_approval->pn_pemutus =  isset($request->pn) ? $request->pn : "";
            $data_approval->nama_pemutus =  isset($request->nama_pemutus) ? $request->nama_pemutus : "";
            $data_approval->jabatan_pemutus =  isset($request->jabatan_pemutus) ? $request->jabatan_pemutus : "";
            $data_approval->tgl_putusan =  Carbon::now()->format('Y-m-d H:i:s');
            $data_approval->catatan_pemutus =  isset($request->catatan_pemutus) ? $request->catatan_pemutus : "";

            if (isset($request->pn)) {
                $approval[] = $data_approval;
            }
        }

        $get_prakarsa->status = $new_status;
        $get_prakarsa->approval = !empty($approval) ? new ApprovalCollection($approval) : null;

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }


        $this->output->responseCode = '00';
        $this->output->responseDesc = $new_status != "32" ? "Berhasil kirim prakarsa ke Atasan" : "Berhasil kirim prakarsa ke CRA";

        return response()->json($this->output);
    }

    public function insertReviewDokumentasiKredit(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->review_dokumentasi_kredit) || !isset($request->review_dokumentasi_kredit) || !is_array($request->review_dokumentasi_kredit)) {
            throw new ParameterException("Parameter review_dokumentasi_kredit tidak valid");
        }
        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_data_lainnya']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        $data_lainnya = json_decode($pengelolaan_keuangan['content_data_lainnya'] != "" ? $pengelolaan_keuangan['content_data_lainnya']->toJson() : "{}", TRUE);

        $data_lainnya['review_dokumentasi_kredit'] = new ReviewDokumentasiKreditCollection($request->review_dokumentasi_kredit);

        $pengelolaan_keuangan['content_data_lainnya'] = new DataLainnya($data_lainnya);

        if (empty($pengelolaan_keuangan->toArray())) {
            $this->prakarsamediumRepo->insertPengelolaanKeuangan($pengelolaan_keuangan);
        }

        $this->prakarsamediumRepo->updatePengelolaanKeuangan($pengelolaan_keuangan);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data';

        return response()->json($this->output);
    }

    public function inquiryListReviewDokumentasiKredit(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_data_lainnya']
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);
        if (empty($pengelolaan_keuangan->toArray())) {
            throw new DataNotFoundException("Data pengelolaan keuangan tidak ditemukan");
        }

        $content_data_lainnya = json_decode($pengelolaan_keuangan['content_data_lainnya'] != "" ? $pengelolaan_keuangan['content_data_lainnya']->toJson() : "{}", TRUE);
        if (!isset($content_data_lainnya['review_dokumentasi_kredit']) || $content_data_lainnya['review_dokumentasi_kredit'] == "") {
            throw new DataNotFoundException("Review dokumentasi kredit tidak ditemukan");
        }
        $review_dokumentasi_kredit = $content_data_lainnya['review_dokumentasi_kredit'];

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry Review dokumentasi kredit';
        $this->output->responseData = $review_dokumentasi_kredit;

        return response()->json($this->output);
    }

    public function insertJawabanQCACRA(Request $request, RestClient $client)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (empty($request->jawaban_qca_cra) || !isset($request->jawaban_qca_cra) || !is_array($request->jawaban_qca_cra)) {
            throw new ParameterException("Parameter jawaban_qca_cra tidak valid");
        }

        $items = [];
        $param_nonfin_las = new stdClass;
        $content_data = ['content_datanonfinansial'];

        $prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);
        if (empty($prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        if (!in_array($prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah");
        }

        $data_nonfinansial = is_array($prakarsa->content_datanonfinansial) ? $prakarsa->content_datanonfinansial : json_decode($prakarsa->content_datanonfinansial !== null ? $prakarsa->content_datanonfinansial->toJson() : '{}', true);
        $new_jawaban_qca_cra = new JawabanQcaCraCollection($request->jawaban_qca_cra);

        $data_nonfinansial['jawaban_qca_cra'] = $new_jawaban_qca_cra->toArray();

        $new_data_nonfinansial = new DataNonFinansialMenengah($data_nonfinansial);
        $prakarsa->content_datanonfinansial = $new_data_nonfinansial;

        $param_nonfin_las->fid_cif_las  = $prakarsa->cif_las;
        $param_nonfin_las->fid_aplikasi = $prakarsa->id_aplikasi;

        foreach ($request->jawaban_qca_cra as $index => $jawaban) {
            if (empty($jawaban['param_db']) || empty($jawaban['nilai']) || empty($jawaban['deskripsi'])){
                throw new InvalidRuleException("Pastikan semua pertanyaan sudah dijawab");
            }

            $item = [];
            $item['param_db']       = $jawaban['param_db'];
            $item['nilai']          = $jawaban['nilai'];
            $item['subparameter']   = $jawaban['param_db'];
            $item['deskripsi']      = $jawaban['deskripsi'];

            array_push($items, $item);
        }

        $param_nonfin_las->items = $items;

        $clientResponse = $client->call($param_nonfin_las, env('BRISPOT_EKSTERNAL_URL'), '/las/insertDataNonFinansialRitkomMenengah');
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
            throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " insert data nonfinansial sme qca ke las gagal");
        }

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses insert Jawaban QCA';

        return response()->json($this->output);
    }

    public function inquiryListBlindQCA(Request $request, RestClient $client)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }

        // get content_datanonfinansial prakarsa
        $refno          = $request->refno;
        $content_data   = ['content_datanonfinansial'];

        $prakarsa = $this->prakarsa_repo->getPrakarsa($refno, $content_data);
        if (empty($prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        if (!in_array($prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah");
        }

        $content_datanonfinansial = $prakarsa->content_datanonfinansial;
        if (!isset($content_datanonfinansial->jawaban_qca_rm) || empty($content_datanonfinansial->jawaban_qca_rm)) {
            throw new InvalidRuleException("RM Belum melakukan pengisian QCA");
        }

        // get data pertanyaan non finansial
        $param              = new stdClass();
        $param->pn          = $request->pn;
        $param->refno       = $refno;
        $param->tp_produk   = $prakarsa->tp_produk;
        $clientResponse     = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryDataNonFinansialSmeQCA');

        if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
            throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " inquiry data nonfinansial sme qca gagal");
        }

        $pertanyaan = [];
        $list_pertanyaan = $clientResponse->responseData;

        foreach ($list_pertanyaan as $tanya) {
            $pilihan_ganda              = new stdClass;
            $pilihan_ganda->poin        = $tanya->poin;
            $pilihan_ganda->deskripsi   = $tanya->deskripsi;

            $pertanyaan[$tanya->param_db]['pertanyaan'] = $tanya->pertanyaan;
            $pertanyaan[$tanya->param_db]['pilihan_ganda'][] = $pilihan_ganda;
        }

        $jawaban_rm = $jawaban_cra = [];

        $jawaban_qca_rm     = $content_datanonfinansial->jawaban_qca_rm;
        $jawaban_qca_cra    = isset($content_datanonfinansial->jawaban_qca_cra) ? $content_datanonfinansial->jawaban_qca_cra : [];

        foreach ($jawaban_qca_rm as $qca_rm) {
            $jawaban_rm[$qca_rm['param_db']] = $qca_rm;
        }

        if (!empty($jawaban_qca_cra)) {
            foreach ($jawaban_qca_cra as $qca_cra) {
                $jawaban_cra[$qca_cra['param_db']] = $qca_cra;
            }
        }

        $path_folder = !empty($prakarsa->path_folder) ? $prakarsa->path_folder : NULL;

        if (!is_dir($path_folder)) {
            // mkdir($path_folder, 0775, true);
        }

        $list_jawaban = [];
        foreach ($pertanyaan as $index => $tanya) {
            $lampiran = null;
            if (isset($jawaban_rm[$index])) {
                $current_lampiran = str_replace("/storage/emulated/0/Android/data/id.co.bri.brispotnew/files/Pictures/", "", $jawaban_rm[$index]['lampiran']);

                $explode_lampiran = explode("/", $current_lampiran);

                array_shift($explode_lampiran);
                $new_lampiran = implode('/', $explode_lampiran);

                $new_path_to_folder = $path_folder . $new_lampiran;

                $hash_link = Str::random(40);
                $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public') . '/securelink/' . $hash_link;
                exec($symlink_url);
                $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link . '/';

                if (file_exists($new_path_to_folder)) {
                    $lampiran = $url_file . $new_lampiran;
                }
            }

            $current_pertanyaan = [];
            $current_pertanyaan['param_db']             = $index;
            $current_pertanyaan['pertanyaan']           = $tanya['pertanyaan'];
            $current_pertanyaan['nilai_rm']             = isset($jawaban_rm[$index]) ? $jawaban_rm[$index]['nilai'] : "";
            $current_pertanyaan['nilai_cra']            = isset($jawaban_cra[$index]) ? $jawaban_cra[$index]['nilai'] : "";
            $current_pertanyaan['deskripsi_jawaban_rm']     = isset($jawaban_rm[$index]) ? $jawaban_rm[$index]['deskripsi'] : "";
            $current_pertanyaan['deskripsi_jawaban_cra']    = isset($jawaban_cra[$index]) ? $jawaban_cra[$index]['deskripsi'] : "";
            $current_pertanyaan['komentar_rm']          = isset($jawaban_rm[$index]) ? $jawaban_rm[$index]['komentar'] : "";
            $current_pertanyaan['lampiran']             = $lampiran;
            $current_pertanyaan['keterangan']           = isset($jawaban_cra[$index]['nilai'], $jawaban_rm[$index]['nilai']) ? $jawaban_rm[$index]['nilai'] == $jawaban_cra[$index]['nilai'] ? "Sesuai" : "Tidak Sesuai" : "";
            $current_pertanyaan['pilihan_jawaban']      = $tanya['pilihan_ganda'];

            array_push($list_jawaban, $current_pertanyaan);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry list Blind QCA';
        $this->output->responseData = $list_jawaban;

        return response()->json($this->output);
    }

    public function inquiryListReviewPemutus(Request $request, RestClient $client)
    {
        if (empty($request->hilfm) || !isset($request->hilfm) || !in_array($request->hilfm, ['196', '197', '014', '173', '221'])) {
            throw new ParameterException("Parameter hilfm tidak valid");
        }
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (empty($request->page) || !isset($request->page)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        if ((empty($request->branch) || !isset($request->branch)) && in_array($request->hilfm, ['196', '197', '014', '173'])) {
            throw new ParameterException("Parameter branch tidak valid");
        }

        $tp_produk = $status = '0';
        $branch_in = [];
        if (in_array($request->hilfm, ['196', '197'])) {
            $status     = '30';
            $tp_produk  = '49';
        } else if (in_array($request->hilfm, ['014', '221'])) {
            $status     = '31';
            $tp_produk  = '49';
        } else {
            $status     = '31';
            $tp_produk  = '50';
        }

        $filter = [
            'nama_debitur'  => isset($request->nama_debitur) && !empty($request->nama_debitur) ? $request->nama_debitur : '' ,
            'tp_produk_in'  => [$tp_produk],
            'nama_ao'       => isset($request->nama_ao) && !empty($request->nama_ao) ? $request->nama_ao : '' ,
            'status'        => $status,
            'page'          => $request->page,
        ];

        if ($request->hilfm != "221") {
            $filter['branch'] = $request->branch;
        }

        if ($request->hilfm == '221') {
            $param = new stdClass;
            $param->pn_sbh = str_pad($request->pn, 8, '0', STR_PAD_LEFT);
            $clientResponse = $client->call($param, env('BRISPOT_CORE_LAS_URL'), '/v1/inquiryListSBCBranchBySBH');

            if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
                throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " gagal mendapatkan list branch sbh LAS");
            }

            foreach ($clientResponse->responseData as $data) {
                isset($data->branch) ? array_push($branch_in, $data->branch) : '';
            }

            $filter['branch_in'] = $branch_in;
        }

        $list_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        if (empty($list_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil Inquiry data Review Pemutus';
        $this->output->responseData = $list_prakarsa;

        return response()->json($this->output);
    }

    public function inquiryCRRMedium(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }

        $content_data = [
            'content_datacrs',
            'content_datapribadi'
        ];

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah");
        }

        if (!isset($get_prakarsa->pn_cra)) {
            throw new InvalidRuleException("Pn CRA tidak ditemukan");
        }

        if ($get_prakarsa->pn_cra != $request->pn) {
            throw new InvalidRuleException("Anda tidak dapat melakukan proses generate CRR score");
        }

        if ($get_prakarsa->status != '34') {
            throw new InvalidRuleException("Tidak dapat melakukan generate CRR, Prakarsa tidak sedang dalam status Pra Komite");
        }

        $content_datacrs                 = [];
        $content_datacrs['score']        = '57.6645930481';
        $content_datacrs['grade']        = 'Baa3';
        $content_datacrs['cutoff']       = 'Y';
        $content_datacrs['definisi']     = 'Pengajuan kredit diterima dan dapat dilajutkan';

        $new_datacrs = new DataCrsMenengah($content_datacrs);

        $new_content_datacrs = new DataCrsMenengahCollection([$new_datacrs]);
        $get_prakarsa->content_datacrs = $new_content_datacrs->toJson();

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil Inquiry data Review Pemutus';
        $this->output->responseData = $new_content_datacrs;

        return response()->json($this->output);
    }

    public function kirimPesertaKomite(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (!isset($request->peserta_prakom) || !is_array($request->peserta_prakom)) {
            throw new ParameterException("Parameter peserta_prakom tidak valid");
        }
        if (!isset($request->uid_pengirim) || empty($request->uid_pengirim)) {
            throw new ParameterException("Parameter uid_pengirim tidak valid");
        }
        if (!isset($request->plafond) || empty($request->plafond)) {
            throw new ParameterException("Parameter plafond tidak valid");
        }
        if (!isset($request->pemutus) || !is_array($request->pemutus)) {
            throw new ParameterException("Parameter pemutus tidak valid");
        }
        if (isset($request->pemrakarsa_tambahan) && !is_array($request->pemrakarsa_tambahan)) {
            throw new ParameterException("Parameter pemrakarsa_tambahan tidak valid");
        }

        $content_data = [
            'content_datapengajuan'
        ];

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }

        $approval = !empty($get_prakarsa->approval) ? json_decode((String) $get_prakarsa->approval) : [];
        $content_datapengajuan = $get_prakarsa->content_datapengajuan != "" && !is_array($get_prakarsa->content_datapengajuan) ? json_decode((string) $get_prakarsa->content_datapengajuan, true) : [];

        $list_pn =  [];
        $pemutus = $request->pemutus; 
        $pemrakarsa_tambahan = isset($request->pemrakarsa_tambahan) ? $request->pemrakarsa_tambahan : [] ;
        $total_approval = $jml_pemutus = $jml_pemrakarsa_tambahan = $total_pemutus = 0;

        if (!empty($approval)) {
            $jml_pemutus = count($request->pemutus);
            $jml_pemrakarsa_tambahan = isset($request->pemrakarsa_tambahan) ? count($request->pemrakarsa_tambahan) : 0;

            $total_approval = count($approval);
            $total_pemutus = $jml_pemutus + $jml_pemrakarsa_tambahan;

            $max = max($total_approval, $total_pemutus);

            for ($i = 0; $i < $max; $i++) {
                if (isset($approval[$i])) {
                    if (isset($approval[$i]->tipe) && strtolower($approval[$i]->tipe) == 'pemutus') {
                        unset($approval[$i]);
                    }

                    if (isset($approval[$i]->tipe) && strtolower($approval[$i]->tipe) == 'pemrakarsa_tambahan') {
                        unset($approval[$i]);
                    }
                }

                if (isset($request->pemutus[$i])) {
                    !in_array($request->pemutus[$i]['pn'], $list_pn) ? array_push($list_pn, $request->pemutus[$i]['pn']) : '';
                }
                if (isset($request->pemrakarsa_tambahan[$i])) {
                    !in_array($request->pemrakarsa_tambahan[$i]['pn'], $list_pn) ? array_push($list_pn, $request->pemrakarsa_tambahan[$i]['pn']) : '';
                }
            }
        }

        // get data pertanyaan non finansial
        if (!empty($list_pn)) {
            $param              = new stdClass();
            $param->pn_in       = $list_pn;
            $param->page        = 1;
            $clientResponse     = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryListPekerja');

            $list_pekerja = [];
            if (isset($clientResponse->responseCode)) {
                if (isset($clientResponse->responseData)) {
                    foreach ($clientResponse->responseData as $pekerja) {
                        $list_pekerja[$pekerja->pn_pekerja] = $pekerja;
                    }
                }

                for ($i = 0; $i < $max; $i++) {    
                    if (isset($request->pemutus[$i])) {
                        $pemutus[$i]['jabatan_pemutus'] = in_array($pemutus[$i]['pn'], array_keys($list_pekerja)) ? $list_pekerja[$pemutus[$i]['pn']]->nama_jabatan : '';
                    }
                    if (isset($request->pemrakarsa_tambahan[$i])) {
                        $pemrakarsa_tambahan[$i]['jabatan_pemutus'] = in_array($pemrakarsa_tambahan[$i]['pn'], array_keys($list_pekerja)) ? $list_pekerja[$pemrakarsa_tambahan[$i]['pn']]->nama_jabatan : '';
                    }
                }
            }
        }

        $content_datapengajuan['peserta_prakom'] = new PesertaPrakomiteMediumCollection($request->peserta_prakom);
        $new_content_datapengajuan = new DataPengajuanMenengah($content_datapengajuan);

        $approval_gabungan = array_merge($approval, $pemutus, $pemrakarsa_tambahan);
        $new_approval = new ApprovalCollection($approval_gabungan);

        $get_prakarsa->approval = $new_approval->toJson();
        $get_prakarsa->content_datapengajuan = $new_content_datapengajuan;
        $get_prakarsa->status = !isset($request->pemrakarsa_tambahan) || empty($request->pemrakarsa_tambahan) ? 4 : 37;

        $param                  = new stdClass();
        $param->id_aplikasi     = (int)$get_prakarsa->id_aplikasi;
        $param->plafond         = (int)$request->plafond;
        $param->uid_pengirim    = (int)$request->uid_pengirim;
        $param->fid_uid_komite  = (int)$request->pemutus[0]['fid_uid_komite'];
        $clientResponse         = $client->call($param, env('BRISPOT_CORE_LAS_URL'), '/v1/kirimPemutusS5');

        if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
            throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " kirim pemutus LAS gagal");
        }

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }


        // produce kafka putusan
        if (in_array($get_prakarsa->status, [4])) {
            $produceKafkaPutusan = $this->produceKafkaPutusan($get_prakarsa->refno);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil kirim peserta komite';

        return response()->json($this->output);
    }

    public function kirimPutusanKreditMedium(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (!isset($request->flag_putusan) || empty($request->flag_putusan)) {
            throw new ParameterException("Parameter flag_putusan tidak valid");
        }
        if (!isset($request->catatan_pemutus) || empty($request->catatan_pemutus)) {
            throw new ParameterException("Parameter catatan_pemutus tidak valid");
        }
        
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }
        
        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }
        
        $approval = !empty($get_prakarsa->approval) ? json_decode((String) $get_prakarsa->approval) : [];
        $new_status = $old_status = $get_prakarsa->status;

        $uid = 0;
        $catatan = "";
        $list_pemutus = $list_pemutus_bisnis = $list_pemutus_risk = [];
        $total_putusan = $total_pemutus = $total_approved = $total_pemrakarsa_tambahan = $total_approved_pemrakarsa_tambahan = $all_pemutus_approved = 0;
        
        if (!empty($approval)) {
            foreach ($approval as $index => $value) {
                if (isset($value->tipe) && in_array($value->tipe, ['pemutus', 'pemrakarsa_tambahan'])) {
                    $list_pemutus[$value->pn] = $value;
                    $jenis = $get_prakarsa->status == "4" ? "pemutus" : "pemrakarsa_tambahan";
                    $pemutus_las = new stdClass;

                    if ($value->pn == $request->pn && $jenis == $value->tipe) {
                        $value->flag_putusan = $request->flag_putusan;
                        $value->tgl_putusan = Carbon::now()->toDateTimeString();
                        $value->catatan_pemutus = $request->catatan_pemutus;
                    }

                    $value->flag_putusan != "" ? $total_approved++ : '';
                    $value->tipe == 'pemrakarsa_tambahan' ? $total_pemrakarsa_tambahan++ : '';
                    $value->tipe == 'pemrakarsa_tambahan' && $value->flag_putusan != '' ? $total_approved_pemrakarsa_tambahan++ : '';
                    $value->tipe == 'pemutus' ? $total_pemutus++ : '';
                    $value->tipe == 'pemutus' && $value->flag_putusan == "1" ? $all_pemutus_approved++ : '';

                    if ($value->tipe == "pemutus" && isset($value->jenis_user)) {
                        $pemutus_las->fid_uid_komite = isset($value->fid_uid_komite) ? $value->fid_uid_komite : 0;
                        $pemutus_las->uid = isset($value->uid) ? $value->uid : 0;
                        $pemutus_las->tgl_putusan = isset($value->tgl_putusan) ? $value->tgl_putusan : '';
                        $pemutus_las->flag_putusan = isset($value->flag_putusan) ? $value->flag_putusan : '';

                        if (strtolower($value->jenis_user) == "bisnis") {
                            array_push($list_pemutus_bisnis, $pemutus_las);
                        } else if (strtolower($value->jenis_user) == "risk") {
                            array_push($list_pemutus_risk, $pemutus_las);
                        }

                        if ($value->flag_putusan == "2") {
                            $uid = $value->fid_uid_komite;
                            $catatan = $value->catatan_pemutus;
                        }
                    }
                    $total_putusan++;
                }
            }
        }

        if (empty($list_pemutus)) {
            throw new InvalidRuleException("Belum ada pemutus yang di-assign ke prakarsa ini");
        }
        if (!in_array($request->pn, array_keys($list_pemutus))) {
            throw new InvalidRuleException("Pn ini tidak termasuk pemutus untuk prakarsa ini");
        }

        if ($total_pemrakarsa_tambahan > 0) {
            $total_pemrakarsa_tambahan == $total_approved_pemrakarsa_tambahan && $get_prakarsa->status == 37 ? $new_status = 4 : '';
        }

        if ($get_prakarsa->status == 4 && $total_putusan == $total_approved) {
            if ($total_pemutus == $all_pemutus_approved) {
                $param              = new stdClass();
                $param->id_aplikasi = (int)$get_prakarsa->id_aplikasi;
                $param->list_anggota_komite_risk        = $list_pemutus_risk;
                $param->list_anggota_komite_bisnis      = $list_pemutus_bisnis;

                $clientResponse     = $client->call($param, env('BRISPOT_CORE_LAS_URL'), '/v1/putusSepakatS5');

                if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
                    throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " inquiry data nonfinansial sme qca gagal");
                }
                $new_status = 100;
            } else {
                $param              = new stdClass();
                $param->id_aplikasi = (int)$get_prakarsa->id_aplikasi;
                $param->uid         = $uid;
                $param->flag_putusan = "2";
                $param->catatan     = $catatan;
                
                $clientResponse     = $client->call($param, env('BRISPOT_EKSTERNAL_URL'), '/las/putusSepakat');
                if (isset($clientResponse->responseCode) && $clientResponse->responseCode <> "00") {
                    throw new ThirdPartyServiceException(isset($clientResponse->responseDesc) ? $clientResponse->responseDesc : " inquiry data nonfinansial sme qca gagal");
                }
                $old_keterangan_lainnya = json_decode($get_prakarsa->keterangan_lainnya);

                $new_status = 0;
                $keterangan_lainnya = new stdClass;
                $keterangan_lainnya->tipe = "tolak_prakarsa";
                $keterangan_lainnya->keterangan = $catatan;

                array_push($old_keterangan_lainnya, $keterangan_lainnya);
                json_encode($old_keterangan_lainnya);
            }
        }

        $get_prakarsa->status = $new_status;
        $new_approval = new ApprovalCollection($approval);
        $get_prakarsa->approval = $new_approval->toJson();

        
        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }
        
        // tembakan kafka untuk update status
        if ($old_status != $new_status) {
            $produceKafkaPoP = $this->produceKafkaPoP($get_prakarsa);
        }
        
        // produce kafka putusan
        if ($old_status != $new_status && in_array($new_status, [4, 100])) {
            $produceKafkaPutusan = $this->produceKafkaPutusan($get_prakarsa->refno);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil kirim putusan';

        return response()->json($this->output);
    }

    public function kirimPrakarsaRMkeReviewer(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }
        if ($get_prakarsa->status <> 1) {
            throw new InvalidRuleException("Gagal kirim Prakarsa ke Reviewer. Prakarsa tidak di RM");
        }

        $param = new stdClass();
        $branch = $get_prakarsa->branch;
        $tp_produk = $get_prakarsa->tp_produk;
        
        if ($tp_produk == 49) {
            $param = new stdClass();
            $param->hilfm_in    = ["196", "197"];
            $param->branch_in   = [$branch];
            $param->include_pgs = true;
            $param->page        = 1;
            $clientResponse = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryListPekerja');
            
            $get_prakarsa->status = 30;
            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                $param = new stdClass();
                $param->branch = str_pad($branch, 5, '0', STR_PAD_LEFT);
                $inquirySbhLas = $client->call($param, env('BRISPOT_CORE_LAS_URL'), '/v1/inquiryMappingSBHByBranch');

                $get_prakarsa->status = 31;
                if (isset($inquirySbhLas->responseCode) && $inquirySbhLas->responseCode != "00") {
                    $param = new stdClass();
                    $param->hilfm       = "014";
                    $param->branch_in   = [$branch];
                    $param->include_pgs = true;
                    $param->page        = 1;
                    $clientResponse = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryListPekerja');

                    if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00" && empty($clientResponse->responseData)) {
                        throw new InvalidRuleException("Pemutus tidak ditemukan");
                    }
                }
            }
        } else if ($tp_produk == 50) {
            $param = new stdClass();
            $param->hilfm       = "173";
            $param->branch_in   = [$branch];
            $param->include_pgs = true;
            $param->page        = 1;

            $clientResponse = $client->call($param, env('BRISPOT_MASTER_URL'), '/v1/inquiryListPekerja');
            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new InvalidRuleException("Reviewer tidak ditemukan");
            }

            $get_prakarsa->status = 31;
        }

        $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }


        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil kirim prakarsa ke reviewer';

        return response()->json($this->output);
    }

    function produceKafkaPutusan($refno)
    {
        $content_data = [
            "content_datapribadi",
            "content_datatmpusaha",
            "content_dataagunan",
            "content_datanonfinansial",
            "content_datapengajuan",
            "content_datafinansial",
            "content_dataprescreening",
            "content_datarekomendasi",
            "content_datacrs",
            "content_dataasuransi"
        ];

        $get_prakarsa = $this->prakarsamediumRepo->getPrakarsa($refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            return 1;
        }

        $prakarsa = new stdClass;
        $prakarsa->id = isset($get_prakarsa->id) ? $get_prakarsa->id : null;
        $prakarsa->refno = isset($get_prakarsa->refno) ? $get_prakarsa->refno : null;
        $prakarsa->tipe_referal = isset($get_prakarsa->tipe_referal) ? $get_prakarsa->tipe_referal : null;
        $prakarsa->referal = isset($get_prakarsa->referal) ? $get_prakarsa->referal : null;
        $prakarsa->status_referal = isset($get_prakarsa->status_referal) ? $get_prakarsa->status_referal : null;
        $prakarsa->branch = isset($get_prakarsa->branch) ? $get_prakarsa->branch : null;
        $prakarsa->nama_branch = isset($get_prakarsa->nama_branch) ? $get_prakarsa->nama_branch : null;
        $prakarsa->insdate = isset($get_prakarsa->insdate) ? $get_prakarsa->insdate : null;
        $prakarsa->upddate = isset($get_prakarsa->upddate) ? $get_prakarsa->upddate : null;
        $prakarsa->expdate = isset($get_prakarsa->expdate) ? $get_prakarsa->expdate : null;
        $prakarsa->jenis_pinjaman = isset($get_prakarsa->jenis_pinjaman) ? $get_prakarsa->jenis_pinjaman : null;
        $prakarsa->tp_produk = isset($get_prakarsa->tp_produk) ? $get_prakarsa->tp_produk : null;
        $prakarsa->nik = isset($get_prakarsa->nik) ? $get_prakarsa->nik : null;
        $prakarsa->nama_debitur = isset($get_prakarsa->nama_debitur) ? $get_prakarsa->nama_debitur : null;
        $prakarsa->cif_las = isset($get_prakarsa->cif_las) ? $get_prakarsa->cif_las : null;
        $prakarsa->cif = isset($get_prakarsa->cif) ? $get_prakarsa->cif : null;
        $prakarsa->id_aplikasi = isset($get_prakarsa->id_aplikasi) ? $get_prakarsa->id_aplikasi : null;
        $prakarsa->id_kredit = isset($get_prakarsa->id_kredit) ? $get_prakarsa->id_kredit : null;
        $prakarsa->kategori_nasabah = isset($get_prakarsa->kategori_nasabah) ? $get_prakarsa->kategori_nasabah : null;
        $prakarsa->plafond_existing = isset($get_prakarsa->plafond_existing) ? $get_prakarsa->plafond_existing : null;

        $prakarsa->content_datapribadi = isset($get_prakarsa->content_datapribadi) && !empty($get_prakarsa->content_datapribadi) ? json_decode((string)$get_prakarsa->content_datapribadi) : null;
        if (isset($prakarsa->content_datapribadi->desc_ll)) {
            unset($prakarsa->content_datapribadi->desc_ll);
        } 

        $prakarsa->content_datatmpusaha = isset($get_prakarsa->content_datatmpusaha) && !empty($get_prakarsa->content_datatmpusaha) ? json_decode((string)$get_prakarsa->content_datatmpusaha) : null;
        if (isset($prakarsa->content_datatmpusaha->desc_ll)) {
            unset($prakarsa->content_datatmpusaha->desc_ll);
        } 

        $prakarsa->content_dataagunan = isset($get_prakarsa->content_dataagunan) && !empty($get_prakarsa->content_dataagunan) ? json_decode((string)$get_prakarsa->content_dataagunan) : null;
        $prakarsa->content_datanonfinansial = isset($get_prakarsa->content_datanonfinansial) && !empty($get_prakarsa->content_datanonfinansial) ? json_decode((string)$get_prakarsa->content_datanonfinansial) : null;
        $prakarsa->content_datapengajuan = isset($get_prakarsa->content_datapengajuan) && !empty($get_prakarsa->content_datapengajuan) ? json_decode((string)$get_prakarsa->content_datapengajuan) : null;

        $prakarsa->content_datafinansial = isset($get_prakarsa->content_datafinansial) && !empty($get_prakarsa->content_datafinansial) ? json_decode((string)$get_prakarsa->content_datafinansial) : null;
        if (isset($prakarsa->content_datafinansial->desc_ll)) {
            unset($prakarsa->content_datafinansial->desc_ll);
        }         

        $prakarsa->content_dataprescreening = isset($get_prakarsa->content_dataprescreening) && !empty($get_prakarsa->content_dataprescreening) ? json_decode((string)$get_prakarsa->content_dataprescreening) : null;
        $prakarsa->content_datarekomendasi = isset($get_prakarsa->content_datarekomendasi) && !empty($get_prakarsa->content_datarekomendasi) ? json_decode((string)$get_prakarsa->content_datarekomendasi) : null;
        $prakarsa->content_datacrs = isset($get_prakarsa->content_datacrs) && !empty($get_prakarsa->content_datacrs) ? json_decode((string)$get_prakarsa->content_datacrs) : null;
        $prakarsa->content_dataasuransi = isset($get_prakarsa->content_dataasuransi) && !empty($get_prakarsa->content_dataasuransi) ? json_decode((string)$get_prakarsa->content_dataasuransi) : null;
        $prakarsa->plafond = isset($get_prakarsa->plafond) ? $get_prakarsa->plafond : null;
        $prakarsa->crs = isset($get_prakarsa->crs) ? $get_prakarsa->crs : null;
        $prakarsa->norek_simpanan = isset($get_prakarsa->norek_simpanan) ? $get_prakarsa->norek_simpanan : null;
        $prakarsa->norek_pinjaman = isset($get_prakarsa->norek_pinjaman) ? $get_prakarsa->norek_pinjaman : null;
        $prakarsa->tgl_rekomendasi = isset($get_prakarsa->tgl_rekomendasi) ? $get_prakarsa->tgl_rekomendasi : null;
        $prakarsa->status = isset($get_prakarsa->status) ? $get_prakarsa->status : null;
        $prakarsa->status_putusan = isset($get_prakarsa->status_putusan) ? $get_prakarsa->status_putusan : null;
        $prakarsa->tgl_putusan = isset($get_prakarsa->tgl_putusan) ? $get_prakarsa->tgl_putusan : null;
        $prakarsa->pn_pemutus = isset($get_prakarsa->pn_pemutus) ? $get_prakarsa->pn_pemutus : null;
        $prakarsa->nama_pemutus = isset($get_prakarsa->nama_pemutus) ? $get_prakarsa->nama_pemutus : null;
        $prakarsa->jabatan_pemutus = isset($get_prakarsa->jabatan_pemutus) ? $get_prakarsa->jabatan_pemutus : null;
        $prakarsa->approval = isset($get_prakarsa->approval) && !empty($get_prakarsa->approval) ? json_decode((string)$get_prakarsa->approval) : null;
        $prakarsa->tgl_pencairan = isset($get_prakarsa->tgl_pencairan) ? $get_prakarsa->tgl_pencairan : null;
        $prakarsa->teller = isset($get_prakarsa->teller) ? $get_prakarsa->teller : null;
        $prakarsa->override = isset($get_prakarsa->override) ? $get_prakarsa->override : null;
        $prakarsa->userid_ao = isset($get_prakarsa->userid_ao) ? $get_prakarsa->userid_ao : null;
        $prakarsa->nama_ao = isset($get_prakarsa->nama_ao) ? $get_prakarsa->nama_ao : null;
        $prakarsa->uid_ao = isset($get_prakarsa->uid_ao) ? $get_prakarsa->uid_ao : null;
        $prakarsa->bibr = isset($get_prakarsa->bibr) ? $get_prakarsa->bibr : null;
        $prakarsa->keterangan = isset($get_prakarsa->keterangan) ? $get_prakarsa->keterangan : null;
        $prakarsa->keterangan_lainnya = isset($get_prakarsa->keterangan_lainnya) && !empty($get_prakarsa->keterangan_lainnya) ? json_decode((string)$get_prakarsa->keterangan_lainnya) : null;
        $prakarsa->email = isset($get_prakarsa->email) ? $get_prakarsa->email : null ;
        $prakarsa->pre_screen = isset($get_prakarsa->pre_screen) ? $get_prakarsa->pre_screen : null ;
        $prakarsa->path_folder = isset($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : null ;
        $prakarsa->size_folder = isset($get_prakarsa->size_folder) ? $get_prakarsa->size_folder : null ;
        $prakarsa->id_channel = isset($get_prakarsa->id_channel) ? $get_prakarsa->id_channel : null ;
        $prakarsa->channel = isset($get_prakarsa->channel) ? $get_prakarsa->channel : null ;
        $prakarsa->path_file = isset($get_prakarsa->path_file) ? $get_prakarsa->path_file : null ;
        $prakarsa->pn_pemutus_collateral = isset($get_prakarsa->pn_pemutus_collateral) ? $get_prakarsa->pn_pemutus_collateral : null ;
        $prakarsa->nama_pemutus_collateral = isset($get_prakarsa->nama_pemutus_collateral) ? $get_prakarsa->nama_pemutus_collateral : null ;
        $prakarsa->jabatan_pemutus_collateral = isset($get_prakarsa->jabatan_pemutus_collateral) ? $get_prakarsa->jabatan_pemutus_collateral : null ;

        try {
            $topic = 'brispot_prakarsa_putusan';
            $producer = Kafka::publishOn($topic, Kafka::SCHEMAFULL_WITH_SERDE);

            $message = KafkaProducerMessage::create($topic, 0)->withBody(json_encode($prakarsa));
            $producer->produce($message);
            $producer->flush(2000);
        } catch (\Throwable $th) {
            Log::channel('daily')->error("Gagal produce cpp pool of pipeline dengan refno " . $get_prakarsa->refno . " | Error : " . $th->getMessage());
        }
    }

    public function inquiryDetailHasilBSA(Request $request)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        $list_rekening_koran = [];
        $list_validasi_bsa = []; 
        $list_file_revisi_validasi_bsa = []; 
        
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, ['content_datapribadi']);
        
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa refno:".$request->refno." tidak ditemukan");
        }

        $path_file = !empty($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;
        $path_folder = !empty($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : NULL;
        
        $listSuplierBuyerAfiliasi = new stdClass;
        $listSuplierBuyerAfiliasi->suplier = isset($get_prakarsa->content_datapribadi->supplier_utama) ? array_filter($get_prakarsa->content_datapribadi->supplier_utama) : [];
        $listSuplierBuyerAfiliasi->buyer = isset($get_prakarsa->content_datapribadi->pelanggan_utama) ? array_filter($get_prakarsa->content_datapribadi->pelanggan_utama) : [];
        $listSuplierBuyerAfiliasi->afiliasi = isset($get_prakarsa->content_datapribadi->daftar_perusahaan_terafiliasi) ? array_filter($get_prakarsa->content_datapribadi->daftar_perusahaan_terafiliasi) : [];
        
        $hash_link = Str::random(40);
        // if(!is_dir($path_folder)) {
        //     mkdir($path_folder, 0775, true);
        // }
        $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
        exec($symlink_url);
        $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link;

        if (isset($path_file->dokumentasi->pengajuan_BSA->rekening_koran) && is_array($path_file->dokumentasi->pengajuan_BSA->rekening_koran)) {
            foreach ($path_file->dokumentasi->pengajuan_BSA->rekening_koran as $key => $value) {
                $list_rek = [
                    'nama_file' => $value,
                    'link_file' => $url_file . '/dokumentasi/pengajuan_BSA/rekening_koran/' . $value 
                ];
                    
                array_push($list_rekening_koran, $list_rek);
            }
        }
        
        if (isset($path_file->dokumentasi->pengajuan_BSA->validasi_bsa) && is_array($path_file->dokumentasi->pengajuan_BSA->validasi_bsa)) {
            foreach ($path_file->dokumentasi->pengajuan_BSA->validasi_bsa as $key => $value) {
                $list_rek2 = [
                    'nama_file' => $value,
                    'link_file' => $url_file . '/dokumentasi/pengajuan_BSA/validasi_bsa/' . $value
                ];
                    
                array_push($list_validasi_bsa, $list_rek2);
            }
        }
        
        if (isset($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa) && is_array($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa)) {
            foreach ($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa as $key => $value) {
                $list_rek3 = [
                    'nama_file' => $value,
                    'link_file' => $url_file . '/dokumentasi/pengajuan_BSA/revisi_validasi_bsa/' . $value
                ];
                    
                array_push($list_file_revisi_validasi_bsa, $list_rek3);
            }
        }
        
        $get_data_keuangan  = $this->prakarsamediumRepo->getKeuanganByRefno($request->refno);
        
        $get_data_pengajuan = $this->prakarsamediumRepo->getPengajuanBsaByRefno($request->refno);
        if (empty($get_data_pengajuan)) {
            throw new DataNotFoundException("Data pengajuan dengan refno:".$request->refno." tidak ditemukan");
        }
        $omset_tervalidasi = $get_data_pengajuan->omset_tervalidasi; 
        $revisi_rm = $get_data_pengajuan->revisi_rm != NULL && $get_data_pengajuan->revisi_rm != "" ? json_decode($get_data_pengajuan->revisi_rm) : NULL;
        
        if (!empty($get_data_keuangan)) {
            $labarugi = $get_data_keuangan->content_data_labarugi != '' ? json_decode($get_data_keuangan->content_data_labarugi, true) : '';
            $max_labarugi = $get_data_keuangan->content_data_labarugi != '' ? $labarugi[max(array_keys($labarugi))] : [];
            $tanggal_posisi = $get_data_keuangan->content_data_labarugi != '' ? $max_labarugi['tanggal_posisi'] : '0';
            $penjualan_bersih = $get_data_keuangan->content_data_labarugi != '' ? $max_labarugi['penjualan_bersih'] : [];
    
            $old_tanggal_posisi = $tanggal_posisi != '' ? ltrim(date("m", intVal($tanggal_posisi)), '0') : '0';
            if ($old_tanggal_posisi < 12) {
                $penjualan_bersih_bulanan = (int)$penjualan_bersih / (int)$old_tanggal_posisi;
                $penjualan_bersih = $penjualan_bersih_bulanan * 12;
            }
    
            $ratio_jualbeli = $penjualan_bersih > 0 ? 100*(($penjualan_bersih - $omset_tervalidasi) / $penjualan_bersih) : 0;
            if ($revisi_rm != NULL) {
                $omset_tervalidasi_rm = $revisi_rm->omset_tervalidasi;
                $ratio_jualbeli_rm = (int)$penjualan_bersih > 0 ? 100*(((int)$penjualan_bersih - (int)$omset_tervalidasi_rm) / (int)$penjualan_bersih) : 0;
                $revisi_rm->omset_tahunan = $omset_tervalidasi_rm != "" ? $penjualan_bersih : 0;
                $revisi_rm->komponen_kas = isset($ratio_jualbeli_rm) && $revisi_rm->omset_tervalidasi != "" ? number_format($ratio_jualbeli_rm, 2, '.', '.') : 0;
            }
        }
        
        $list_validasi = new stdClass;
        $list_validasi->pn_ao               = $get_data_pengajuan->pn_ao;
        $list_validasi->nama_ao             = $get_data_pengajuan->nama_ao;
        $list_validasi->transaksi_bulanan   = $get_data_pengajuan->transaksi_bulanan;
        $list_validasi->ratio_jualbeli      = $get_data_pengajuan->ratio_jualbeli;
        $list_validasi->saldo_bulanan       = $get_data_pengajuan->saldo_bulanan;
        $list_validasi->omset_tervalidasi   = $omset_tervalidasi;
        $list_validasi->omset_tahunan       = isset($penjualan_bersih) ? $penjualan_bersih : 0;
        $list_validasi->komponen_kas        = isset($ratio_jualbeli) ? number_format($ratio_jualbeli, 2, '.', '.') : 0;
        $list_validasi->opini_rm            = $get_data_pengajuan->catatan_rm;
        $list_validasi->status              = $get_data_pengajuan->status;
        
        $responseData = new stdClass;
        $responseData->list_validasi_bsa = $list_validasi;
        $responseData->revisi_validasi_bsa = $revisi_rm;
        $responseData->listSuplierBuyerAfiliasi = $listSuplierBuyerAfiliasi;
        $responseData->validasi_bsa = $list_validasi_bsa;
        $responseData->list_rekening_koran = $list_rekening_koran;
        $responseData->file_revisi_validasi_bsa = $list_file_revisi_validasi_bsa;

        $this->output->responseCode = "00";
        $this->output->responseDesc = "Sukses inquiry";
        $this->output->responseData = $responseData;

        return response()->json($this->output);
    }

    public function insertDataDebiturPeroranganSME(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak boleh kosong");
        }

        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak boleh kosong");
        }
        
        if (!isset($request->uid) || empty($request->uid)) {
            throw new ParameterException("Parameter uid tidak boleh kosong");
        }

        if (!isset($request->branch) || empty($request->branch)) {
            throw new ParameterException("Parameter branch tidak boleh kosong");
        }
        
        if (!isset($request->bibr) || empty($request->bibr)) {
            throw new ParameterException("Parameter bibr tidak boleh kosong");
        }

        if (!isset($request->tp_produk) || empty($request->tp_produk)) {
            throw new ParameterException("Parameter tp produk tidak boleh kosong");
        }

        $tp_produk = $request->tp_produk;
        $branch = $request->branch;

        $tp_prod_sme = ['49', '50'];

        if (in_array($tp_produk, $tp_prod_sme)) {
            $cek_piloting = $this->prakarsamediumRepo->cekParamPiloting($branch, 'sme');
            if (!$cek_piloting) {
                throw new ParameterException("Tipe produk tidak dapat dipilih. Unit kerja Anda tidak termasuk dalam unit kerja piloting");
            }
        }

        if (!isset($request->no_ktp) || empty($request->no_ktp)) {
            throw new ParameterException("NIK debitur tidak boleh kosong");
        }
        $refno = $request->refno;
        $nik = $request->no_ktp;

        if (strlen(trim($request->no_ktp)) < 10 || strlen(trim($request->no_ktp)) > 16) {
            throw new ParameterException("NIK debitur salah.");
        }

        if (!is_numeric($request->no_ktp)) {
            throw new ParameterException("NIK debitur salah. format harus angka");
        }

        if (!isset($request->nama_debitur) || empty($request->nama_debitur)) {
            throw new ParameterException("Nama debitur tidak boleh kosong");
        }
        $nama = $request->nama_debitur;

        if (!isset($request->agama) && !in_array($request->agama, ['ISL', 'KRI', 'KAT', 'HIN', 'BUD', 'ZZZ'])) {
            throw new ParameterException("Isian agama salah");
        }

        if ($request->agama == 'ZZZ' && empty($request->ket_agama)) {
            throw new ParameterException("Keterangan agama harus diisi.");
        }

        if (!isset($request->tempat_lahir) || empty($request->tempat_lahir)) {
            throw new ParameterException("Tempat lahir tidak boleh kosong");
        }

        if (!isset($request->status_perkawinan) && !in_array($request->status_perkawinan, ['1', '2', '3'])) {
            throw new ParameterException("Isian status perkawinan salah");
        }

        if (!isset($request->perjanjian_pisah_harta) && !in_array($request->perjanjian_pisah_harta, ['0', '1'])) {
            throw new ParameterException("Isian perjanjian pisah harta salah");
        }

        if (!isset($request->jenis_kelamin) && !in_array($request->jenis_kelamin, ['l', 'p'])) {
            throw new ParameterException("Isian jenis kelamin salah");
        }

        if (!isset($request->jenis_rekening) && !in_array($request->jenis_rekening, ['1', '2', '3'])) {
            throw new ParameterException("Isian jenis rekening salah");
        }

        if (!isset($request->pernah_pinjam) && !in_array($request->pernah_pinjam, ['Ya', 'Tidak'])) {
            throw new ParameterException("Isian pernah pinjam bank lain salah");
        }

        //1=0 s/d 10jt, 2=10 s/d 50jt,3=50 s/d 100jt,4= 100jt s/d 1M,5= lebih dari 1M
        if (!isset($request->transaksi_normal_harian) && !in_array($request->transaksi_normal_harian, ['1', '2', '3', '4', '5'])) {
            throw new ParameterException("Isian transaksi normal harian salah");
        }
        
        if (!isset($request->flag_postfin)){
            throw new ParameterException("Update aplikasi BRISPOT dengan versi yang terbaru.");
        }

        if (!in_array($request->flag_postfin, ['0', '1']) && $request->tp_produk == "5") {
            throw new ParameterException("Flag postfin harus diisi untuk tipe produk 5.");
        }

        if ($request->jenis_pekerjaan == 'ZZZZZ') {
            $request->ket_pekerjaan = 'Pekerjaan Lainnya';
        } else {
            $request->ket_pekerjaan = '';
        }

        if ($request->tujuan_membuka_rekening == 'ZZ' && (!isset($request->ket_buka_rekening) || (isset($request->ket_buka_rekening) && trim($request->ket_buka_rekening) == ''))) {
            throw new ParameterException("Keterangan buka rekening harus diisi.");
        }

        if (!isset($request->penghasilan_per_bulan) && !in_array($request->penghasilan_per_bulan, ['G1', 'G2', 'G3', 'G4', 'G5'])) {
            throw new ParameterException("Isian penghasilan per bulan salah");
        }

        if (!empty($request->tgl_lahir)){
            if ($request->tgl_lahir != date('Y-m-d', strtotime($request->tgl_lahir))) {
                throw new ParameterException("Format tgl_lahir salah");
            }
        }
        
        if (!empty($request->tgl_lahir_pasangan)){
            if ($request->tgl_lahir_pasangan != Carbon::parse($request->tgl_lahir_pasangan)->format('Y-m-d')) {
                throw new ParameterException("Format tgl_lahir_pasangan salah");
            }
        }

        if (!empty($request->tgl_mulai_usaha)){
            if ($request->tgl_mulai_usaha != date('Y-m-d', strtotime($request->tgl_mulai_usaha))) {
                throw new ParameterException("Format tgl_mulai_usaha salah");
            }
        }

        if (!empty($request->tgl_mulai_debitur)){
            if ($request->tgl_mulai_debitur != date('Y-m-d', strtotime($request->tgl_mulai_debitur))) {
                throw new ParameterException("Format tgl_mulai_debitur salah");
            }
        }

        if (!in_array($request->flag_postfin, array("0", "1")) && $request->tp_produk == "5") {
            throw new ParameterException("Flag postfin harus diisi untuk tipe produk 5.");
        }

        if (isset($request->lain_lain)) {
            $lain_lain = new stdClass;
            $json_debitur = $request->lain_lain;
            $lain_lain->jabatan = $request->jabatan;
            $lain_lain->deskripsi = $json_debitur['deskripsi'];
            $lain_lain->nama_debitur_2 = $json_debitur['nama_debitur_2'];
            $lain_lain->nama_debitur_3 = $json_debitur['nama_debitur_3'];
            $lain_lain->nama_debitur_4 = $json_debitur['nama_debitur_4'];
        }
        
        if(in_array($tp_produk, ['47', '48', '49', '50'])){
            $supplier_utama = [];
            $pelanggan_utama = [];
            $daftar_perusahaan_terafiliasi = [];
            $supplier_utama = $request->supplier_utama;
            $pelanggan_utama = $request->pelanggan_utama;
            $daftar_perusahaan_terafiliasi = $request->daftar_perusahaan_terafiliasi;
        }

        if(in_array($tp_produk, ['49', '50'])){
            
            $arr_currentBalance = [];
            $arr_hitung_plafon_ke_total_eksposure = [];
            $jml_currentBalance = 0;
            $fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya);
        }

        // get data sekon menengah
        $bidangUsaha = $client->call( [ "sekon_lbu"=> $request->bidang_usaha ], env('BRISPOT_MASTER_URL'), '/v1/inquirySekonMenengah'); 

        if (isset($bidangUsaha->responseCode) && $bidangUsaha->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        if (isset($bidangUsaha->responseData) && is_object($bidangUsaha->responseData)){
            $current_data = new stdClass;
            $current_data->sekon_sid = $bidangUsaha->responseData->sekon_sid;
        }

        // get data produk pinjaman
        $cekProduk = $client->call([ "tp_produk"=> $request->tp_produk ], env('BRISPOT_MASTER_URL'), '/v1/inquiryProdukPinjaman'); 

        if (isset($cekProduk->responseCode) && $cekProduk->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }
        
        if (isset($cekProduk->responseData) && is_object($cekProduk->responseData)){
            $data_produk = new stdClass;
            $data_produk->nama_produk = $cekProduk->responseData->nama_produk;
            $data_produk->segmen = $cekProduk->responseData->segmen;
        }

        // get prakarsa
        $getciflas = $this->prakarsamediumRepo->getPrakarsa($request->refno, [
            "content_datapribadi",  
            "content_dataagunan", 
            "content_datarekomendasi",
        ]
        );

        $id_aplikasi = $getciflas->id_aplikasi;
        $norek_pinjaman = $getciflas->norek_pinjaman;
        $kategori_nasabah = $getciflas->kategori_nasabah;
        if($tp_produk == '5' && ($kategori_nasabah == '' || $kategori_nasabah == '0' || $kategori_nasabah == null) && $request->flag_postfin == '0'){
            throw new InvalidRuleException('Untuk prakarsa baru non Postfin silakan menggunakan produk SME < 1 M atau SME 1 - 5 M.');
        }

        $ceknik = true;
        $namabrinets = "";
        $kodeukerbrinets = "";
        $cekdbnik = $this->prakarsamediumRepo->cekNikBrinets($request->refno, $nik);

        if (empty($cekdbnik)) {
            // inquiry nik las diganti dengan inquiry cif by nik ke esb
            $requestInquiryCif = [
                'kode_uker' => str_pad($request->branch, 5, "0", STR_PAD_LEFT),
                'nik' => $request->no_ktp
            ];
            $clientResponse = $client->call($requestInquiryCif, env('BRISPOT_EKSTERNAL_URL'), '/esbcore/inquiryCIFbyNIK');

            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new DataNotFoundException($clientResponse->responseDesc);
            }

            $clientResponseInquiryCifData = $clientResponse->responseData;
            $namabrinets = isset($clientResponseInquiryCifData->cust_name) ? $clientResponseInquiryCifData->cust_name : null;
            $nikbrinets = isset($clientResponseInquiryCifData->nik) ? $clientResponseInquiryCifData->nik : null;

            $dataInsertTmp = [
                "NIK" => isset($clientResponseInquiryCifData->nik) ? $clientResponseInquiryCifData->nik : null,
                "NAMA" => isset($clientResponseInquiryCifData->cust_name) ? $clientResponseInquiryCifData->cust_name : null,
                "CIF" => isset($clientResponseInquiryCifData->cif) ? $clientResponseInquiryCifData->cif : null,
                "UKER_ASAL" => isset($clientResponseInquiryCifData->branch) ? $clientResponseInquiryCifData->branch : null,
            ];

            if (isset($nikbrinets) && isset($namabrinets) && isset($clientResponseInquiryCifData->branch)) {
                similar_text(strtolower(trim($namabrinets)), strtolower($nama), $persensimilar);
                if (trim($nikbrinets) == $request->no_ktp && $persensimilar >= 80) {
                    $this->prakarsamediumRepo->insertCekBrinets($refno, $request->no_ktp, json_encode($dataInsertTmp));
                } else {
                    $namabrinets = trim($namabrinets);
                    $kodeukerbrinets = trim($clientResponseInquiryCifData->branch);
                    $ceknik = false;
                }
            }
        } else {
            if ($cekdbnik->data != '') {
                $datarek = json_decode($cekdbnik->data);
                similar_text(strtolower(trim($datarek->NAMA)), strtolower($nama), $persensimilar);
                if (isset($datarek->NIK) && isset($datarek->NAMA) && (trim($datarek->NIK) != $nik || $persensimilar < 80)) {
                    // inquiry nik las diganti dengan inquiry cif by nik ke esb
                    $requestInquiryCif = [
                        'kode_uker' => str_pad($request->branch, 5, "0", STR_PAD_LEFT),
                        'nik' => $nik
                    ];
                    $clientResponse = $client->call($requestInquiryCif, env('BRISPOT_EKSTERNAL_URL'), '/esbcore/inquiryCIFbyNIK');

                    if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                        throw new DataNotFoundException($clientResponse->responseDesc);
                    }

                    $clientResponseInquiryCifData = $clientResponse->responseData;
                    $namabrinets = isset($clientResponseInquiryCifData->cust_name) ? $clientResponseInquiryCifData->cust_name : null;
                    $nikbrinets = isset($clientResponseInquiryCifData->nik) ? $clientResponseInquiryCifData->nik : null;

                    $dataInsertTmp = [
                        "NIK" => isset($clientResponseInquiryCifData->nik) ? $clientResponseInquiryCifData->nik : null,
                        "NAMA" => isset($clientResponseInquiryCifData->cust_name) ? $clientResponseInquiryCifData->cust_name : null,
                        "CIF" => isset($clientResponseInquiryCifData->cif) ? $clientResponseInquiryCifData->cif : null,
                        "UKER_ASAL" => isset($clientResponseInquiryCifData->branch) ? $clientResponseInquiryCifData->branch : null,
                    ];

                    if (isset($nikbrinets) && isset($namabrinets) && isset($clientResponseInquiryCifData->branch)) {
                        similar_text(strtolower(trim($namabrinets)), strtolower($nama), $persensimilar);
                        if (trim($nikbrinets) == $nik && $persensimilar >= 80) {                                        
                            $this->prakarsamediumRepo->deleteCekBrinets($refno);
                            $this->prakarsamediumRepo->insertCekBrinets($refno, $nik, json_encode($dataInsertTmp));
                        } else {
                            $namabrinets = trim($namabrinets);
                            $kodeukerbrinets = trim($clientResponseInquiryCifData->branch);
                            $ceknik = false;
                        }
                    }
                }
            }
        }

        if ($ceknik == false) {
            $getbranch = $this->prakarsamediumRepo->getBranch($request->branch);
            $branchname = $getbranch->BRDESC ;
            throw new InvalidRuleException('NIK ' . $nik . ' terdaftar atas nama ' . $request->nama_debitur . ', nasabah unit kerja ' . $request->branch .' '. $branchname . '.');
        }

        if ($getciflas->toArray() > 0 && $getciflas->status > 1) {
            throw new InvalidRuleException('NIK ' . trim($nik) . ' sedang ada proses prakarsa berjalan. di unit kerja ' . $request->branch . '.');
        }

        if ($getciflas->refno != $request->refno) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (($getciflas->content_datacrs != '' || $getciflas->content_datacrs != null) && $getciflas->status != '1') {
            throw new InvalidRuleException('Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.');
        }

        $content_datapribadi_host = new stdClass;
        $content_datarekomendasi_host = new stdClass;
        $content_dataagunan_host = new stdClass;
        $cek_pengurus = false;
        
        if (isset($getciflas->content_datarekomendasi)) {
            $content_datarekomendasi_host = json_decode($getciflas->content_datarekomendasi);
        }
        if (isset($getciflas->content_dataagunan)) {
            $content_dataagunan_host = $getciflas->content_dataagunan;
        }
        
        if (isset($getciflas->content_datapribadi)) {
            $content_datapribadi_host = $getciflas->content_datapribadi;
        }
        
        // if (isset($getciflas->content_datanonfinansial)) {
        //     $content_datanonfinansial_host = $getciflas->content_datanonfinansial;
        // }

        if (isset($content_datapribadi_host->pengurus)) {
            $cek_pengurus = true;
            $pengurus = $content_datapribadi_host->pengurus;
        }

        // $nonFin = !empty($getciflas->content_datanonfinansial) ? json_encode($getciflas->content_datanonfinansial) : null;
        // $a = $nonFin->jawaban_qca_rm;

        $ciflas = $getciflas->cif_las;
        $datapribadi = new stdClass;
        $datafinansial = new stdClass;
        
        //LOAD DATA PENJAMIN
        if ($cek_pengurus == true) {
            $datapribadi->pengurus = ($pengurus);
        }

        //cek prescreening
        $arrjk = array('l' => '1', 'p' => '2', 'L' => '1', 'P' => '2');
        $jkdeb = isset($arrjk[$request->jenis_kelamin]) ? $arrjk[$request->jenis_kelamin] : '';
        $tgldeb = (isset($request->tgl_lahir) ? trim($request->tgl_lahir) : '');

        $cekpre = true;
        $msgcekpre = "";
        $aliasdeb = $request->nama_debitur_1;
        
        $this->prakarsamediumRepo->deleteCekBrinets($request->refno);
        
        if($request->status_perkawinan == '3'){
            //reset data pasangan jika status perkawinan cerai
            $request->nama_pasangan = '';
            $request->tgl_lahir_pasangan = '';
            $request->no_ktp_pasangan = '';
        }

        if (in_array($request->tp_produk, ['1','2','28'])) {
            $request->segmen_bisnis_bri = "RITEL";
            $request->kategori_portofolio = "175";
            $request->hub_bank = "9900"; 
            $request->usia_mpp = (isset($request->usia_mpp) ? $request->usia_mpp : '');
            $request->id_instansi = (isset($request->id_instansi) ? $request->id_instansi : '');
        } else {
            $request->segmen_bisnis_bri = "MIKRO";
            $request->kategori_portofolio = "172";
            $request->hub_bank = "9900";
        }

        $id_aplikasi = $getciflas->id_aplikasi;
        $norek_pinjaman = $getciflas->norek_pinjaman;
        $kategori_nasabah = $getciflas->kategori_nasabah;

        // insert data pribadi
        $clientResponse = new stdClass;
        $clientResponse = $client->call(
            [
                "refno"=> $request->refno,
                "id_aplikasi"=> !empty($id_aplikasi) ? $id_aplikasi : '0',
                "no_rekening"=> !empty($norek_pinjaman) ? $norek_pinjaman : '0',
                "suplesi"=> !empty($kategori_nasabah) ? $kategori_nasabah : '0',
                "no_ktp"=> $nik,
                "pn"=> $request->pn,
                "bibr"=> $request->bibr,
                "nama_debitur"=> $request->nama_debitur,
                "flag_postfin"=> $request->flag_postfin,
                "flag_sp"=> !empty($kategori_nasabah) ? $kategori_nasabah : '0',
                "tp_produk"=> $request->tp_produk,
                "uid"=> $request->uid,
                "cif_las" => $ciflas,
                "kode_cabang"=> $request->branch,
                "expired_ktp"=> $request->expired_ktp,
                "nama_debitur_1"=> $request->nama_debitur,
                "nama_tanpa_gelar"=> $request->alias, 
                "nama_debitur_2"=> $request->nama_debitur_2,
                "nama_debitur_3"=> $request->nama_debitur_3,
                "nama_debitur_4"=> $request->nama_debitur_4,
                "agama"=> $request->agama,
                "ket_agama"=> $request->ket_agama,
                "tgl_lahir"=> !empty($request->tgl_lahir) ? Carbon::parse($request->tgl_lahir)->format('dmY'): '',
                "tempat_lahir"=> $request->tempat_lahir,
                "nama_pasangan"=> $request->nama_pasangan,
                "tgl_lahir_pasangan"=> !empty($request->tgl_lahir_pasangan) ? Carbon::parse($request->tgl_lahir_pasangan)->format('dmY') : '',
                "no_ktp_pasangan"=> $request->no_ktp_pasangan,
                "status_perkawinan"=> $request->status_perkawinan,
                "perjanjian_pisah_harta"=> $request->perjanjian_pisah_harta,
                "jumlah_tanggungan"=> $request->jumlah_tanggungan,
                "status_gelar"=> $request->status_gelar,
                "keterangan_status_gelar"=> $request->keterangan_status_gelar,
                "jenis_kelamin"=> $request->jenis_kelamin,
                "nama_ibu"=> $request->nama_ibu,
                "alamat"=> $request->alamat,
                "kelurahan"=> $request->kelurahan,
                "kecamatan"=> $request->kecamatan,
                "kabupaten"=> $request->dati2,
                "kode_pos"=> $request->kode_pos,
                "alamat_domisili"=> isset($request->alamat_domisili) ? trim($request->alamat_domisili . ' ' . $request->rt_domisili . ' ' . $request->rw_domisili) : '',
                "kodepos_domisili"=> $request->kodepos_domisili,
                "kelurahan_domisili"=> $request->kelurahan_domisili,
                "kecamatan_domisili"=> $request->kecamatan_domisili,
                "kota_domisili"=> $request->kota_domisili,
                "propinsi_domisili"=> $request->provinsi_domisili,
                "fixed_line"=> (isset($request->fixed_line) ? trim($request->fixed_line) : '0'),
                "no_hp"=> $request->no_hp,
                "lama_menetap"=> $request->lama_menetap,
                "email"=> $request->email,
                "tgl_mulai_usaha"=> !empty($request->tgl_mulai_usaha) ? Carbon::parse($request->tgl_mulai_usaha)->format('dmY') : Carbon::now()->format('dmY'),
                "kewarganegaraan"=> "ID",
                "negara_domisili"=> "ID",
                "kepemilikan_tempat_tinggal"=> $request->kepemilikan_tempat_tinggal,
                "golongan_debitur_sid"=> '907',
                "golongan_debitur_lbu"=> '886',
                "nama_kelg"=> $request->nama_kelg,
                "telp_kelg"=> $request->telp_kelg,
                "tgl_mulai_debitur"=> !empty($request->tgl_mulai_debitur) ? Carbon::parse($request->tgl_mulai_debitur)->format('dmY') : Carbon::now()->format('dmY'),
                "jenis_rekening"=> $request->jenis_rekening,
                "nama_bank_lain"=> $request->nama_bank_lain,
                "pekerjaan_debitur"=> $request->pekerjaan_debitur,
                "alamat_usaha"=> $request->alamat_usaha,
                "nama_perusahaan"=> $request->nama_perusahaan,
                "bidang_usaha"=> $current_data->sekon_sid,
                "jenis_pekerjaan"=> $request->jenis_pekerjaan,
                "ket_pekerjaan"=> $request->ket_pekerjaan,
                "jabatan"=> $request->jabatan,
                "kelurahan_usaha"=> $request->kelurahan_usaha,
                "kecamatan_usaha"=> $request->kecamatan_usaha,
                "kota_usaha"=> $request->kota_usaha,
                "propinsi_usaha"=> $request->provinsi_usaha,
                "kodepos_usaha"=> $request->kodepos_usaha,
                "tujuan_membuka_rekening"=> $request->tujuan_membuka_rekening,
                "ket_buka_rekening"=> $request->ket_buka_rekening,
                "penghasilan_per_bulan"=> $request->penghasilan_per_bulan,
                "resident_flag"=> 'Y',
                "customer_type"=> "I",
                "pernah_pinjam"=> $request->pernah_pinjam,
                "sumber_utama"=> '2',
                "federal_wh_code"=> '1',
                "sub_customer_type"=> 'I',
                "transaksi_normal_harian"=> $request->transaksi_normal_harian,
                "alias"=> $request->alias,
                "segmen_bisnis_bri"=> $data_produk->nama_produk,
                "kategori_portofolio"=> '172',
                "hub_bank"=> $request->hub_bank,
                "id_instansi"=> $request->id_instansi,
                "usia_mpp"=> $request->usia_mpp,
                
            ], env('BRISPOT_EKSTERNAL_URL'), '/las/insertDataDebtPerorangan');

        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
            throw new DataNotFoundException($clientResponse->responseDesc);
        }

        // survey data pribadi
        $datapribadi->no_ktp = (isset($request->no_ktp) ? trim($request->no_ktp) : ''); 
        $datapribadi->nama_debitur = (isset($request->nama_debitur) ? trim($request->nama_debitur) : '');
        $datapribadi->agama = (isset($request->agama) ? trim($request->agama) : '');
        $datapribadi->ket_agama = (isset($request->ket_agama) ? trim($request->ket_agama) : '');
        $datapribadi->tgl_lahir = (isset($request->tgl_lahir) ? trim($request->tgl_lahir) : '');
        $datapribadi->tempat_lahir = (isset($request->tempat_lahir) ? trim($request->tempat_lahir) : '');
        $datapribadi->status_perkawinan = $request->status_perkawinan;
        $datapribadi->nama_pasangan = $request->nama_pasangan;
        $datapribadi->tgl_lahir_pasangan = $request->tgl_lahir_pasangan;
        $datapribadi->no_ktp_pasangan = $request->no_ktp_pasangan;

        $datapribadi->perjanjian_pisah_harta = (isset($request->perjanjian_pisah_harta) ? trim($request->perjanjian_pisah_harta) : '');
        $datapribadi->jumlah_tanggungan = (isset($request->jumlah_tanggungan) ? trim($request->jumlah_tanggungan) : '0');
        $datapribadi->status_gelar = (isset($request->status_gelar) ? trim($request->status_gelar) : '');
        $datapribadi->keterangan_status_gelar = (isset($request->keterangan_status_gelar) ? trim($request->keterangan_status_gelar) : '');
        $datapribadi->jenis_kelamin = (isset($request->jenis_kelamin) ? trim($request->jenis_kelamin) : '');
        $datapribadi->nama_ibu = (isset($request->nama_ibu) ? trim($request->nama_ibu) : '');
        $datapribadi->alamat = (isset($request->alamat) ? trim($request->alamat) : '');
        $datapribadi->rt = (isset($request->rt) ? trim($request->rt) : '');
        $datapribadi->rw = (isset($request->rw) ? trim($request->rw) : '');
        $datapribadi->kelurahan = (isset($request->kelurahan) ? trim($request->kelurahan) : '');
        $datapribadi->kecamatan = (isset($request->kecamatan) ? trim($request->kecamatan) : '');
        $datapribadi->kabupaten = (isset($request->kabupaten) ? trim($request->kabupaten) : '');
        $datapribadi->provinsi = (isset($request->provinsi) ? trim($request->provinsi) : '');
        $datapribadi->dati2 = (isset($request->dati2) ? trim($request->dati2) : '');
        $datapribadi->kode_pos = (isset($request->kode_pos) ? trim($request->kode_pos) : '');
        $datapribadi->alamat_domisili = (isset($request->alamat_domisili) ? trim($request->alamat_domisili) : '');
        $datapribadi->rt_domisili = (isset($request->rt_domisili) ? trim($request->rt_domisili) : '');
        $datapribadi->rw_domisili = (isset($request->rw_domisili) ? trim($request->rw_domisili) : '');
        $datapribadi->kelurahan_domisili = (isset($request->kelurahan_domisili) ? trim($request->kelurahan_domisili) : '');
        $datapribadi->kecamatan_domisili = (isset($request->kecamatan_domisili) ? trim($request->kecamatan_domisili) : '');
        $datapribadi->kabupaten_domisili = (isset($request->kota_domisili) ? trim($request->kota_domisili) : '');
        $datapribadi->provinsi_domisili = (isset($request->provinsi_domisili) ? trim($request->provinsi_domisili) : '');
        $datapribadi->dati2_domisili = (isset($request->dati2_domisili) ? trim($request->dati2_domisili) : '');
        $datapribadi->kode_pos_domisili = (isset($request->kodepos_domisili) ? trim($request->kodepos_domisili) : '');
        $datapribadi->fixed_line = (isset($request->fixed_line) ? trim($request->fixed_line) : '0');
        $datapribadi->no_hp = (isset($request->no_hp) ? trim($request->no_hp) : '');
        $datapribadi->lama_menetap = (isset($request->lama_menetap) ? trim($request->lama_menetap) : '');
        $datapribadi->email = (isset($request->email) ? trim($request->email) : '');
        $datapribadi->kepemilikan_tempat_tinggal = (isset($request->kepemilikan_tempat_tinggal) ? trim($request->kepemilikan_tempat_tinggal) : '');
        $datapribadi->tgl_mulai_debitur = (isset($request->tgl_mulai_debitur) ? trim($request->tgl_mulai_debitur) : '');
        $datapribadi->jenis_rekening = (isset($request->jenis_rekening) ? trim($request->jenis_rekening) : '');
        $datapribadi->nama_bank_lain = (isset($request->nama_bank_lain) ? trim($request->nama_bank_lain) : '');
        $datapribadi->pekerjaan_debitur = (isset($request->pekerjaan_debitur) ? trim($request->pekerjaan_debitur) : '');
        $datapribadi->resident_flag = (isset($request->resident_flag) ? trim($request->resident_flag) : '');
        $datapribadi->pernah_pinjam = (isset($request->pernah_pinjam) ? trim($request->pernah_pinjam) : '');
        $datapribadi->transaksi_normal_harian = (isset($request->transaksi_normal_harian) ? trim($request->transaksi_normal_harian) : '');
        $datapribadi->alias = (isset($request->nama_debitur) ? trim($request->nama_debitur) : '');
        $datapribadi->flag_postfin = (isset($request->flag_postfin) ? trim($request->flag_postfin) : '');
        $datapribadi->alamat_usaha = (isset($request->alamat_usaha) ? trim($request->alamat_usaha) : '');
        $datapribadi->nama_perusahaan = (isset($request->nama_perusahaan) ? trim($request->nama_perusahaan) : '');
        $datapribadi->tgl_mulai_usaha = (isset($request->tgl_mulai_usaha) ? trim($request->tgl_mulai_usaha) : '');
        $datapribadi->bidang_usaha = (isset($current_data->sekon_sid) ? trim($current_data->sekon_sid) : '');
        $datapribadi->jenis_pekerjaan = (isset($request->jenis_pekerjaan) ? trim($request->jenis_pekerjaan) : '');
        $datapribadi->ket_pekerjaan = $request->ket_pekerjaan;
        $datapribadi->jabatan = (isset($request->jabatan) ? trim($request->jabatan) : '');
        $datapribadi->kelurahan_usaha = (isset($request->kelurahan_usaha) ? trim($request->kelurahan_usaha) : '');
        $datapribadi->kecamatan_usaha = (isset($request->kecamatan_usaha) ? trim($request->kecamatan_usaha) : '');
        $datapribadi->kota_usaha = (isset($request->kota_usaha) ? trim($request->kota_usaha) : '');
        $datapribadi->provinsi_usaha = (isset($request->provinsi_usaha) ? trim($request->provinsi_usaha) : '');
        $datapribadi->kodepos_usaha = (isset($request->kodepos_usaha) ? trim($request->kodepos_usaha) : '');
        $datapribadi->tujuan_membuka_rekening = (isset($request->tujuan_membuka_rekening) ? trim($request->tujuan_membuka_rekening) : '');
        $datapribadi->ket_buka_rekening = (isset($request->ket_buka_rekening) ? trim($request->ket_buka_rekening) : '');
        $datapribadi->penghasilan_per_bulan = (isset($request->penghasilan_per_bulan) ? trim($request->penghasilan_per_bulan) : '');
        $latitude = (isset($request->latitude) ? trim($request->latitude) : '');
        $longitude = (isset($request->longitude) ? trim($request->longitude) : '');
        $datapribadi->latitude = $latitude;
        $datapribadi->longitude = $longitude;
        $datapribadi->desc_ll = (isset($request->desc) ? trim($request->desc) : '');
        $datapribadi->total_exposure = (isset($request->total_exposure) ? trim($request->total_exposure) : '');
        $datapribadi->jenis_badan_usaha_ket = (isset($request->jenis_badan_usaha_ket) ? trim($request->jenis_badan_usaha_ket) : '');
        $datapribadi->golongan_debitur_ket = (isset($request->golongan_debitur_ket) ? trim($request->golongan_debitur_ket) : '');
        $datapribadi->golongan_debitur_lbu_ket = (isset($request->golongan_debitur_lbu_ket) ? trim($request->golongan_debitur_lbu_ket) : '');
        $datapribadi->hub_debitur_ket = (isset($request->hub_debitur_ket) ? trim($request->hub_debitur_ket) : '');
        $datapribadi->bidang_usaha_brinets_ket = (isset($request->bidang_usaha_brinets_ket) ? trim($request->bidang_usaha_brinets_ket) : '');
        $datapribadi->customer_type_ket = (isset($request->customer_type_ket) ? trim($request->customer_type_ket) : '');
        $datapribadi->sub_customer_type_ket = (isset($request->sub_customer_type_ket) ? trim($request->sub_customer_type_ket) : '');
        $datapribadi->nama_bank_lain_ket = (isset($request->nama_bank_lain_ket) ? trim($request->nama_bank_lain_ket) : '');
        $datapribadi->bidang_usaha_ket = (isset($request->bidang_usaha_ket) ? trim($request->bidang_usaha_ket) : '');
        $datapribadi->bidang_usaha_ojk = (isset($request->bidang_usaha_ojk) ? trim($request->bidang_usaha_ojk) : '');
        $datapribadi->bidang_usaha_ojk_ket = (isset($request->bidang_usaha_ojk_ket) ? trim($request->bidang_usaha_ojk_ket) : '');
        $datapribadi->jabatan_ket = (isset($request->jabatan_ket) ? trim($request->jabatan_ket) : '');
        $datapribadi->jenis_pekerjaan_ket = (isset($request->jenis_pekerjaan_ket) ? trim($request->jenis_pekerjaan_ket) : '');
        $datapribadi->pekerjaan_debitur_ket = (isset($request->pekerjaan_debitur_ket) ? trim($request->pekerjaan_debitur_ket) : '');
        $datapribadi->tp_produk_ket = (isset($request->tp_produk_ket) ? trim($request->tp_produk_ket) : '');
        $datapribadi->agama_ket = (isset($request->agama_ket) ? trim($request->agama_ket) : '');
        $datapribadi->status_gelar_ket = (isset($request->status_gelar_ket) ? trim($request->status_gelar_ket) : '');
        $datapribadi->segmen_bisnis_bri = $data_produk->nama_produk;

        //LOAD DATA PENJAMIN
        if ($cek_pengurus == true) {
            $datapribadi->pengurus = ($pengurus);
        }

        $datapribadi->lain_lain = '';
        if (isset($request->lain_lain)){
            $requestLain = $request->lain_lain;
            $dataPribadiLain = new stdClass;
            $dataPribadiLain->jabatan = (isset($requestLain['jabatan']) ?  trim($requestLain['jabatan']) : '');
            $dataPribadiLain->deskripsi = (isset($requestLain['deskripsi']) ?  trim($requestLain['deskripsi']) : '');
            $dataPribadiLain->nama_debitur_2 = (isset($requestLain['nama_debitur_2']) ? $requestLain['nama_debitur_2'] : '');
            $dataPribadiLain->nama_debitur_3 = (isset($requestLain['nama_debitur_3']) ? $requestLain['nama_debitur_3'] : '');
            $dataPribadiLain->nama_debitur_4 = (isset($requestLain['nama_debitur_4']) ? $requestLain['nama_debitur_4'] : '');
            $datapribadi->lain_lain = $dataPribadiLain;
        }

        if (in_array($request->tp_produk, ['47','48','49','50'])) {
            if (count($supplier_utama) != 0) {
                $datapribadi->supplier_utama = $supplier_utama;
            }
            if (count($pelanggan_utama) != 0){
                $datapribadi->pelanggan_utama = $pelanggan_utama;
            }
            if (count($daftar_perusahaan_terafiliasi) != 0){
                $datapribadi->daftar_perusahaan_terafiliasi = $daftar_perusahaan_terafiliasi;
            }
        }

        if(in_array($tp_produk, ['49', '50'])){
            $datafinansial->total_exposure_khtpk = (isset($request->total_exposure_khtpk) ? $request->total_exposure_khtpk : '0');
            $datafinansial->fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $datafinansial->fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya);
        }

        if(in_array($tp_produk, ['47','48']) && in_array($kategori_nasabah, ["1", "2", "4"])){
            $datafinansial->total_exposure_khtpk = (isset($request->total_exposure_khtpk) ? $request->total_exposure_khtpk : '0');
            $datafinansial->total_exposure_sebelum_putusan = (isset($request->total_exposure_sebelum_putusan) ? $request->total_exposure_sebelum_putusan : '0');
            $datafinansial->fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $datafinansial->fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya); 
        }

        //update data brispot

        $ket_segmen = 'ritel';
        if($tp_produk == '50'){
            $ket_segmen = 'menengah';
        } 

        $idkredit = 0;

        if (isset($clientResponse->responseData[0])) {
            if (in_array($kategori_nasabah, array("1", "2", "3", "4"))) { // 1 = perpanjangan, 2 = suplesi, 3 = restruk, 4 = deplesi
                $idkredit = isset($clientResponse->responseData[0]->ID_KREDIT) ? trim($clientResponse->responseData[0]->ID_KREDIT) : '0';
                $idaplikasi = isset($clientResponse->responseData[0]->ID_APLIKASI) ? trim($clientResponse->responseData[0]->ID_APLIKASI) : '0';

                if (!empty($content_datarekomendasi_host)) {
                    foreach ($content_datarekomendasi_host as $key => $row_datarekomendasi) {
                        $row_datarekomendasi = (array) $row_datarekomendasi;
                        if ($key == "1") {
                            $row_datarekomendasi['id_kredit'] = $idkredit;
                            $content_temp[1] = $row_datarekomendasi;
                        }

                        if ($key == "rekomenAdk") {
                            foreach ($row_datarekomendasi as $row_rekomenAdk) {
                                $row_rekomenAdk = (array) $row_rekomenAdk;
                                $row_rekomenAdk['id_kredit'] = $idkredit;
                                $content_temp['rekomenAdk'][$idkredit] = $row_rekomenAdk;
                            }
                        }
                    }
                }

                $content_datarekomendasi_host = $content_temp;
                if (!empty((array) $content_dataagunan_host)) {
                    $content_dataagunan_host = $content_dataagunan_host->toArray();
                    foreach ($content_dataagunan_host as $content_index => $content) {
                        $content_dataagunan_host[$content_index]['fid_kredit'] = $idkredit;
                        $content_dataagunan_host[$content_index]['id_kredit'] = $idkredit;
                        $content_dataagunan_host[$content_index]['fid_aplikasi'] = $idaplikasi;
                        $content_dataagunan_host[$content_index]['id_aplikasi'] = $idaplikasi;
                    }

                    $content_dataagunan_host = json_encode($content_dataagunan_host);
                    // $content_dataagunan_host = preg_replace('/"fid_kredit":""/i', '"fid_kredit":"' . $idkredit . '"', preg_replace('/"id_kredit":""/i', '"id_kredit":"' . $idkredit . '"', $content_dataagunan_host)); //ganti id_kredit yang kosong
                    // $content_dataagunan_host = preg_replace('/"fid_aplikasi":""/i', '"fid_aplikasi":"' . $idaplikasi . '"', preg_replace('/"id_aplikasi":""/i', '"id_aplikasi":"' . $idaplikasi . '"', $content_dataagunan_host)); //ganti id_kredit yang kosong
                } else {
                    $content_dataagunan_host = json_encode((array) $content_dataagunan_host);
                }

            } else {
                if ($clientResponse->responseData[0]->ID_APLIKASI == $getciflas->id_aplikasi) {
                    $idkredit = $getciflas->id_kredit;
                }
            }
        }

        $arrdata = array(
            'upddate' => date('Y-m-d H:i:s'), 
            'uid_ao' => trim($request->uid),
            'bibr' => trim($request->bibr),
            'content_datapribadi' => json_encode($datapribadi),
            'content_datafinansial' => json_encode($datafinansial),
            'nik' => trim($request->no_ktp),
            'nama_debitur' => trim($request->nama_debitur),
            'segmen' => trim($ket_segmen),
            'tp_produk' => trim($request->tp_produk),
            'jenis_pinjaman' =>$data_produk->nama_produk,
            'cif_las' => (isset($clientResponse->responseData[0]->CIF_LAS) ? $clientResponse->responseData[0]->CIF_LAS : '0'),
            'id_aplikasi' => (isset($clientResponse->responseData[0]->ID_APLIKASI) ? $clientResponse->responseData[0]->ID_APLIKASI : '0'),
            'id_kredit' => $idkredit,
            'email' => (isset($request->email) ? trim($request->email) : '')
        );

        if (in_array($kategori_nasabah, array("1", "2", "3", "4"))) { // 1 = perpanjangan, 2 = suplesi, 3 = restruk, 4 = deplesi
            $arrdata['content_datarekomendasi'] = json_encode($content_datarekomendasi_host);
            $arrdata['content_dataagunan'] = $content_dataagunan_host;
        }

        $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);
        
        $response = [];
        foreach ($clientResponse->responseData as $data) {
            $new_response = new stdClass;
            $new_response->CIF_LAS = $data->CIF_LAS;
            $new_response->ID_APLIKASI = $data->ID_APLIKASI;
            $new_response->FLAG_POSTFIN = $data->FLAG_POSTFIN;
            $response[] = $new_response;
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data pribadi';
        $this->output->responseData = !empty($response) ? $response : [];
        
        return response()->json($this->output);
    }

	public function inquiryDokumentasiByPathFile(Request $request)
    {
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        
        if (!isset($request->path_file) || empty($request->path_file)) {
            throw new ParameterException("Parameter path file tidak boleh kosong");
        }

        if (!isset($request->nama_file) || empty($request->nama_file)) {
            throw new ParameterException("Parameter nama file tidak boleh kosong");
        }
        
        $path_folder = base_path('public');
        $path_file = '/' . $request->path_file . '/';
        
        $nama_file = $request->nama_file; 
        
        if (empty($path_file) || empty($path_folder)) {
            throw new DataNotFoundException("File belum tersedia");
        }

        if(!file_exists($path_folder)) {
            throw new InvalidRuleException("Folder utama tidak ditemukan");
        }

        $hash_link = Str::random(40);
        if(!is_dir($path_folder)) {
            mkdir($path_folder, 0775, true);
        }
        $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
        exec($symlink_url);
        $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link;

        $path_file = $url_file . $path_file . $nama_file; 

        $file_dokumen = new stdClass;
        $file_dokumen->path_file = $path_file;
        
        $this->output->responseCode = "00";
        $this->output->responseDesc = "Sukses download dokumen";
        $this->output->responseData = $file_dokumen;

        return response()->json($this->output);
    }
	
	public function uploadDokumenMediumUpperSmall(Request $request)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (!isset($request->file_name) || empty($request->file_name)) {
            throw new ParameterException("Parameter file_name tidak valid");
        }

        if (!isset($request->file_upload) || empty($request->file_upload)) {
            throw new ParameterException("Paramater file_upload kosong");
        }

        $file_upload = $request->file('file_upload');
        if (!in_array($file_upload->extension(), ['pdf','jpeg','jpg','png','xls','xlsx','xlsm','rar','zip','doc','docx'])) {
            throw new InvalidRuleException("Format file_upload tidak valid, harus pdf/jpeg/jpg/png/xls/xlsx/xlsm/rar/zip/doc/docx");
        }

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:".$request->refno." tidak ditemukan");
        }

        $directory = env('PATH_NFS_PRAKARSA').'/'.$get_prakarsa->branch.'/'.$get_prakarsa->refno;
        if(!is_dir($directory)) {
            if(!mkdir($directory, 0755, true)) {
                throw new \Exception("Folder utama prakarsa tidak berhasil dibentuk");
            }
        }
        $get_prakarsa->path_folder = $directory;

        $path_file = isset($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;

        if(in_array($request->file_name, ['validasi_bsa'])) {
            if (!isset($path_file->dokumentasi)) {
                $path_file->dokumentasi = new stdClass();
            }
            if (!isset($path_file->dokumentasi->pengajuan_BSA)) {
                $path_file->dokumentasi->pengajuan_BSA = new stdClass();
            }
            if (isset($path_file->dokumentasi->pengajuan_BSA->validasi_bsa) && is_array($path_file->dokumentasi->pengajuan_BSA->validasi_bsa)) { 
                
                if (!in_array($request->file_name, $path_file->dokumentasi->pengajuan_BSA->validasi_bsa)) {
                    array_push($path_file->dokumentasi->pengajuan_BSA->validasi_bsa, $request->file_name . '.' . $file_upload->getClientOriginalExtension());
                }
            } else {
                $path_file->dokumentasi->pengajuan_BSA->validasi_bsa = [$request->file_name . '.' . $file_upload->getClientOriginalExtension()];
            }
            
            $directory = env('PATH_NFS_PRAKARSA').'/'.$get_prakarsa->branch.'/'.$get_prakarsa->refno.'/dokumentasi/pengajuan_BSA/validasi_bsa';
        } 

        elseif(in_array($request->file_name, ['rekening_koran'])) {
            if (!isset($path_file->dokumentasi)) {
                $path_file->dokumentasi = new stdClass();
            }
            if (!isset($path_file->dokumentasi->pengajuan_BSA)) {
                $path_file->dokumentasi->pengajuan_BSA = new stdClass();
            }
            if (isset($path_file->dokumentasi->pengajuan_BSA->rekening_koran) && is_array($path_file->dokumentasi->pengajuan_BSA->rekening_koran)) { 
                
                if (!in_array($request->file_name, $path_file->dokumentasi->pengajuan_BSA->rekening_koran)) {
                    array_push($path_file->dokumentasi->pengajuan_BSA->rekening_koran, $request->file_name . '.' . $file_upload->getClientOriginalExtension());
                }
            } else {
                $path_file->dokumentasi->pengajuan_BSA->rekening_koran = [$request->file_name . '.' . $file_upload->getClientOriginalExtension()];
            }
            
            $directory = env('PATH_NFS_PRAKARSA').'/'.$get_prakarsa->branch.'/'.$get_prakarsa->refno.'/dokumentasi/pengajuan_BSA/rekening_koran';
        }
        
        elseif(in_array($request->file_name, ['revisi_validasi_bsa'])) {
            if (!isset($path_file->dokumentasi)) {
                $path_file->dokumentasi = new stdClass();
            }
            if (!isset($path_file->dokumentasi->pengajuan_BSA)) {
                $path_file->dokumentasi->pengajuan_BSA = new stdClass();
            }
            if (isset($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa) && is_array($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa)) { 
                
                if (!in_array($request->file_name, $path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa)) {
                    array_push($path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa, $request->file_name . '.' . $file_upload->getClientOriginalExtension());
                }
            } else {
                $path_file->dokumentasi->pengajuan_BSA->revisi_validasi_bsa = [$request->file_name . '.' . $file_upload->getClientOriginalExtension()];
            }
            
            $directory = env('PATH_NFS_PRAKARSA').'/'.$get_prakarsa->branch.'/'.$get_prakarsa->refno.'/dokumentasi/pengajuan_BSA/revisi_validasi_bsa';
        }
        
        elseif(in_array($request->file_name, ['dokumentasi_analisa_finansial'])) {
            if (!isset($path_file->dokumentasi)) {
                $path_file->dokumentasi = new stdClass();
            }
            
            if (isset($path_file->dokumentasi->dokumentasi_analisa_finansial) && is_array($path_file->dokumentasi->dokumentasi_analisa_finansial)) { 
                
                if (!in_array($request->file_name, $path_file->dokumentasi->dokumentasi_analisa_finansial)) {
                    array_push($path_file->dokumentasi->dokumentasi_analisa_finansial, $request->file_name . '.' . $file_upload->getClientOriginalExtension());
                }
            } else {
                $path_file->dokumentasi->dokumentasi_analisa_finansial = [$request->file_name . '.' . $file_upload->getClientOriginalExtension()];
            }
            
            $directory = env('PATH_NFS_PRAKARSA').'/'.$get_prakarsa->branch.'/'.$get_prakarsa->refno.'/dokumentasi/dokumentasi_analisa_finansial';
        }
        
        if(isset($directory) && !is_dir($directory)) {
            if(!mkdir($directory, 0755, true)) {
                throw new \Exception("Folder ".$directory." tidak berhasil dibentuk");
            }
        }
        $file_upload->move($directory, $request->file_name.'.'.$file_upload->getClientOriginalExtension());
        
        $get_prakarsa->path_file = $path_file;

        $arrdata = array(
            'upddate' => Carbon::now()->format('Y-m-d H:i:s'),
            'path_file' => json_encode($path_file)
        );

        $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data badan usaha';
        $this->output->responseData = !empty($clientResponse->responseData) ? $clientResponse->responseData : [];

        return response()->json($this->output);
    }
	
	public function hitungPotensiRefinancing(Request $request) {
        
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak ditemukan");
        }
        
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid.");
        }
        
        if (!isset($request->max_jumlah_kredit)) {
            throw new ParameterException("Parameter maksimal jumlah kredit tidak valid.");
        }
       
        if (!isset($request->nwc)) {
            throw new ParameterException("Parameter nwc tidak valid.");
        }
        $nwc = $request->nwc;
        
        $get_keuangan_refno = $this->prakarsamediumRepo->getKeuanganByRefno($request->refno);
        
        if (empty($get_keuangan_refno)) {
            throw new DataNotFoundException("Data Tidak Tersedia");
        }
    
        if (empty($get_keuangan_refno->content_data_neraca) || !isset($get_keuangan_refno->content_data_neraca) || $get_keuangan_refno->content_data_neraca == "") {
            throw new DataNotFoundException("Data neraca tidak tersedia");
        }
    
        if (empty($get_keuangan_refno->content_data_asumsi) || !isset($get_keuangan_refno->content_data_asumsi) || $get_keuangan_refno->content_data_asumsi == "") {
            throw new DataNotFoundException("Data asumsi tidak tersedia");
        }
    
        if (empty($get_keuangan_refno->content_data_labarugi) || !isset($get_keuangan_refno->content_data_labarugi) || $get_keuangan_refno->content_data_labarugi == "") {
            throw new DataNotFoundException("Data kaba rugi tidak tersedia");
        }
    
        // get data neraca
        $neraca = json_decode($get_keuangan_refno->content_data_neraca, true);
        $max_arr_neraca = max(array_keys($neraca));
        $max_neraca = !empty($neraca[$max_arr_neraca]) ? $neraca[$max_arr_neraca] : $neraca[$max_arr_neraca-1];
        
        // get data asumsi
        $asumsi = json_decode($get_keuangan_refno->content_data_asumsi, true);
        $value = max($asumsi);
        $max_arr_asumsi = array_search($value, $asumsi);
        
        $max_asumsi = !empty($asumsi[$max_arr_asumsi]) ? $asumsi[$max_arr_asumsi] : $asumsi[$max_arr_asumsi-1];
    
        // get data laba rugi
        $labarugi = json_decode($get_keuangan_refno->content_data_labarugi, true);
        $max_arr_labarugi = max(array_keys($labarugi));
        
        $max_labarugi = !empty($labarugi[$max_arr_labarugi]) ? $labarugi[$max_arr_labarugi] : $labarugi[$max_arr_labarugi-1];
    
        $dor = $max_asumsi["days_of_receivable"];
        $doi = $max_asumsi["days_of_inventory"];
        $wcto = $dor + $doi;
        
        $tanggal_posisi = ltrim(date("m", strtotime($max_labarugi['tanggal_posisi'])), '0');
        $periode = $tanggal_posisi > 0 ? $tanggal_posisi*30 : 0;
        
        
        // mencari sales projection
        $penjualan_bersih = $max_labarugi["penjualan_bersih"];
        $max_labarugi_min1 = isset($labarugi[$max_arr_labarugi]) ? $max_arr_labarugi-1 : $max_arr_labarugi-2;
        
        // penjualan bersih min 1
        $labarugi_min1 = $labarugi[$max_labarugi_min1]["penjualan_bersih"];
        $trend_periode_penjualan = ($penjualan_bersih/$tanggal_posisi*12)/$labarugi_min1*100;
        
        // hpp min 1
        $hpp = $max_labarugi["hpp"];
        $hpp_min_1 = $labarugi[$max_labarugi_min1]["hpp"];
        $trend_periode_hpp = round(($hpp/$tanggal_posisi*12)/$hpp_min_1*100);
    
        // out of pocket exprenses
        $ope = $max_labarugi["hpp"] + $max_labarugi["biaya_penjualan_umum_adm"];
    
        $sales = $penjualan_bersih;
        $sales_projection = $trend_periode_penjualan;
    
        // hitung wcto perhitungan potensi refinancing
        // working capital projection
        $wcp = floor(($wcto * $ope * number_format($sales_projection/100, 2)) / $periode);
        $nilai_sds = number_format(($nwc / $wcp) * 100);
    
        // working capital gap
        $wcg = $wcp - $nwc;
    
        // AP projection
        $hutang_dagang = !empty($max_neraca["hutang_dagang"]) ? $max_neraca["hutang_dagang"] : 0;
        
        $AP_projection = ($hutang_dagang*($sales_projection/100));
        
        // working capital credit needs
        $wccn = $wcg - $AP_projection;  // maksimum kebutuhan modal kerja
        
        // working capital gap value with (SDS = 30%)
        $wcg_value = $nilai_sds >= 30 ? (70/100)*$wcp : 0;
        
        // working capital credit needs with (SDS = 30%)
        $wccn_value = floor($wcg_value - $AP_projection);
    
        // potential working capital refinancing loan
        $pwcfl = $nilai_sds >= 30 ? floor($wccn_value - $wccn) : 0;
        
        $responseData = new stdClass;
        $responseData->potensi_refinancing_modal_kerja = $pwcfl;
        $responseData->kebutuhan_kredit_modal_kerja = $request->max_jumlah_kredit + $pwcfl;
    
        $this->output->responseCode = "00";
        $this->output->responseDesc = "Berhasil hitung potensi refinancing";
        $this->output->responseData = $responseData;
        
        return response()->json($this->output);
    }

    public function hitungPendekatanBiaya(Request $request) {
        
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak ditemukan");
        }
        $pn = $request->pn;
        
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid.");
        }
        $refno = trim($request->refno);
        
        if (!isset($request->kas_minimum)) {
            throw new ParameterException("Parameter kas minimum tidak valid.");
        }
        $kas_minimum = trim($request->kas_minimum);
        
        if (!isset($request->hutang_dagang)) {
            throw new ParameterException("Parameter hutang dagang tidak valid.");
        }
        $hutang_dagang = trim($request->hutang_dagang);
        
        $get_keuangan_refno = $this->prakarsamediumRepo->getKeuanganByRefno($refno);
        
        if (empty($get_keuangan_refno)) {
            throw new DataNotFoundException("Data Tidak Tersedia");
        }
        
        if (empty($get_keuangan_refno->content_data_labarugi) || !isset($get_keuangan_refno->content_data_labarugi) || $get_keuangan_refno->content_data_labarugi == "") {
            throw new DataNotFoundException("Data laba rugi tidak tersedia");
        }
        
        if (empty($get_keuangan_refno->content_data_neraca) || !isset($get_keuangan_refno->content_data_neraca) || $get_keuangan_refno->content_data_neraca == "") {
            throw new DataNotFoundException("Data neraca tidak tersedia");
        }
        
        if (empty($get_keuangan_refno->content_data_asumsi) || !isset($get_keuangan_refno->content_data_asumsi) || $get_keuangan_refno->content_data_asumsi == "") {
            throw new DataNotFoundException("Data asumsi tidak tersedia");
        }
        
        $labarugi = json_decode($get_keuangan_refno->content_data_labarugi, true);
        $max_arr_labarugi = max(array_keys($labarugi));
        $max_labarugi = !empty($labarugi[$max_arr_labarugi]) ? $labarugi[$max_arr_labarugi] : $labarugi[$max_arr_labarugi-1];
        
        $neraca = json_decode($get_keuangan_refno->content_data_neraca, true);
        $max_arr_neraca = max(array_keys($neraca));
        $max_neraca = !empty($neraca[$max_arr_neraca]) ? $neraca[$max_arr_neraca] : $neraca[$max_arr_neraca-1];
        
        $asumsi = json_decode($get_keuangan_refno->content_data_asumsi, true);
        $max_arr_asumsi = max(array_keys($asumsi));
        $max_asumsi = !empty($asumsi[$max_arr_asumsi]) ? $asumsi[$max_arr_asumsi] : $asumsi[$max_arr_asumsi-1];
        
        $dor = isset($max_asumsi["days_of_receivable"]) ? $max_asumsi["days_of_receivable"] : 0;
    
        $aktiva_lancar = isset($max_neraca["aktiva_lancar"]) ? $max_neraca["aktiva_lancar"] : 0;
        $hutang_lancar = isset($max_neraca["total_pasiva_lancar"]) ? $max_neraca["total_pasiva_lancar"] : 0;
        $nwc = $aktiva_lancar - $kas_minimum - $hutang_lancar;
    
        $proyeksi_peningkatan_biaya = isset($max_asumsi["kenaikan_proyeksi_penjualan"]) ? $max_asumsi["kenaikan_proyeksi_penjualan"] : 0;
    
        $tanggal_posisi = $max_labarugi['tanggal_posisi'];
        $hpp = isset($max_labarugi['hpp']) ? $max_labarugi['hpp'] : 0;
        $biaya_operasional = isset($max_labarugi['biaya_operasional']) ? $max_labarugi['biaya_operasional'] : 0;
    
        $total_biaya_tahunan = $hpp + $biaya_operasional;
        $total_biaya_bulanan = 0;
        $old_tanggal_posisi = ltrim(date("m", strtotime($tanggal_posisi)), '0');
        if ($old_tanggal_posisi < 12) {
            $total_biaya_bulanan = $total_biaya_tahunan / $old_tanggal_posisi;
        }
        
        $proyeksi_biaya_bulanan = $total_biaya_bulanan > 0 ? ($proyeksi_peningkatan_biaya / 100) * $total_biaya_bulanan : 0;
    
        $nilai_pendekatan = $proyeksi_biaya_bulanan > 0 ? floor(($dor/30) * $proyeksi_biaya_bulanan) : 0;
        $nilai_pendekatan_kurang_nwc = $nilai_pendekatan - $nwc;
        $kebutuhan_modalkerja_tahunan = $nilai_pendekatan_kurang_nwc - $hutang_dagang;
        $kebutuhan_modalkerja_bulanan = $kebutuhan_modalkerja_tahunan > 0 ? floor($kebutuhan_modalkerja_tahunan / 12) : 0;
    
        
        $responseData = new stdClass;
        $responseData->kebutuhan_modal_kerja_bulanan = $kebutuhan_modalkerja_bulanan;
        $responseData->kebutuhan_modal_kerja_tahunan = $kebutuhan_modalkerja_tahunan;
    
        $this->output->responseCode = "00";
        $this->output->responseDesc = "Berhasil hitung pendekatan biaya";
        $this->output->responseData = $responseData;

        return response()->json($this->output);
    }

    public function hitungSimulasiPembayaranKembali(Request $request) {
        
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak ditemukan");
        }
        $pn = $request->pn;
        
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid.");
        }
        $refno = trim($request->refno);
        
        if (!isset($request->kredit_yg_diberikan) || $request->kredit_yg_diberikan == '') {
            throw new ParameterException("Parameter kredit tidak valid.");
        }
        $kredit_yg_diberikan = trim($request->kredit_yg_diberikan);
        
        if (!isset($request->bunga_tahunan) || empty($request->bunga_tahunan)) {
            throw new ParameterException("Parameter bunga tahunan tidak valid.");
        }
        $bunga_tahunan = trim($request->bunga_tahunan);
    
        $get_keuangan_refno = $this->prakarsamediumRepo->getKeuanganByRefno($refno);
        
        if (empty($get_keuangan_refno)) {
            throw new DataNotFoundException("Data Tidak Tersedia");
        }
    
        if (empty($get_keuangan_refno->content_data_labarugi) || !isset($get_keuangan_refno->content_data_labarugi) || $get_keuangan_refno->content_data_labarugi == "") {
            throw new DataNotFoundException("Data laba rugi tidak tersedia");
        }
    
        if (empty($get_keuangan_refno->content_data_neraca) || !isset($get_keuangan_refno->content_data_neraca) || $get_keuangan_refno->content_data_neraca == "") {
            throw new DataNotFoundException("Data neraca tidak tersedia");
        }
    
        $bulan_list = [12, 24, 36];
        $ratio = [75, 50];
        $co_menurun = [];
        $co_tetap = [];
        $laba_bersih = 0;
        
        // get data labarugi
        $labarugi = json_decode($get_keuangan_refno->content_data_labarugi, true);
        $max_arr_labarugi = max(array_keys($labarugi));
        $max_labarugi = !empty($labarugi[$max_arr_labarugi]) ? $labarugi[$max_arr_labarugi] : $labarugi[$max_arr_labarugi-1];
        
        // get data neraca
        $neraca = json_decode($get_keuangan_refno->content_data_neraca, true);
        $max_arr_neraca = max(array_keys($neraca));
        $max_neraca = !empty($neraca[$max_arr_neraca]) ? $neraca[$max_arr_neraca] : $neraca[$max_arr_neraca-1];
    
        $tanggal_posisi = $max_labarugi['tanggal_posisi'];
        $laba_bersih = $max_labarugi['laba_bersih'];
        $old_tanggal_posisi = ltrim(date("m", strtotime($tanggal_posisi)), '0');
        if ($old_tanggal_posisi < 12) {
            $laba_bersih = ($laba_bersih / $old_tanggal_posisi) * 12;
        }
    
        // hitung lababersih tahunan dan bulanan
        $lababersih = $max_labarugi["laba_bersih"];
        $biaya_penyusutan = isset($max_labarugi['biaya_penyusutan']) ? $max_labarugi['biaya_penyusutan'] : 0;
        $prive = $max_neraca["prive"];
        $lababersih_tahunan = ($lababersih + $biaya_penyusutan - $prive) * (12/$old_tanggal_posisi);
        $lababersih_bulanan = $lababersih_tahunan > 0 ? $lababersih_tahunan / 12 : 0;
    
    
        $sukubunga_bulanan = $bunga_tahunan / 12;
        for ($i = 0; $i < count($bulan_list); $i++) {
            $val_thres = new stdClass;
            $lama_pinjaman = $bulan_list[$i];
        
            $RPC = (1-1 / (pow((1+$sukubunga_bulanan/100), $lama_pinjaman))) / ($sukubunga_bulanan / 100);
        
            $pembayaran_total = floor(($kredit_yg_diberikan * $lama_pinjaman) / $RPC);
            $pembayaran_bulanan = floor($pembayaran_total / $lama_pinjaman);
            $laba_bersih_bulanan = floor($laba_bersih / 12);
    
            $total_bayar_bulanan = floor(($ratio[0] / 100) * $laba_bersih_bulanan);
            $co_menurun['threshold'] = $total_bayar_bulanan;
            $ratio_co = number_format(($pembayaran_bulanan/$laba_bersih_bulanan) * 100, 2, '.', '.');
            $round_ratio = (($pembayaran_bulanan/$laba_bersih_bulanan) * 100);
            $co_menurun[$bulan_list[$i] / 12 . " tahun"] = [
                'IDR'   => $pembayaran_bulanan,
                'ratio' => $ratio_co,
                'rating' => $round_ratio > $ratio[0] ? 'merah' : 'hijau'
            ];
    
            // hitung co tetap
            if ($bulan_list[$i] == 12) {
                $ct_lama_pinjam = $lama_pinjaman / 12;
    
                $RPC_ = (1-1 / (pow((1+$bunga_tahunan/100), $ct_lama_pinjam))) / ($bunga_tahunan / 100);
    
                $ct_pembayaran_total = $pembayaran_total = floor(($kredit_yg_diberikan * $ct_lama_pinjam) / $RPC_);
                $ct_bunga = $ct_pembayaran_total - $kredit_yg_diberikan;
                $ct_bayar_bulanan = floor($ct_bunga / 12);
                $ct_total_bayar_bulanan = floor(($ratio[1] / 100) * $laba_bersih_bulanan);
                $co_tetap['threshold'] = $ct_total_bayar_bulanan;
                $ratio_tt = number_format(($ct_bayar_bulanan/$laba_bersih_bulanan) * 100, 2, '.', '.');
                $round_ratio_tt = round(($ct_bayar_bulanan/$laba_bersih_bulanan) * 100);
                $co_tetap[$bulan_list[$i] / 12 . " tahun"] = [
                    'IDR'   => $ct_bayar_bulanan,
                    'ratio' => $ratio_tt,
                    'rating' => $round_ratio_tt > $ratio[1] ? 'merah' : 'hijau'
                ];
    
            }
        }
        if (!isset($max_labarugi['biaya_penyusutan']) && isset($max_labarugi['biaya_penyusutan_amortisasi'])) {
            $biaya_penyusutan = $max_labarugi['biaya_penyusutan_amortisasi'];
        }
    
        $responseData = new stdClass;
        $responseData->kredit_yg_diberikan = $kredit_yg_diberikan;
        $responseData->lababersih_penyusutan_tahunan = $lababersih_tahunan;
        $responseData->lababersih_penyusutan_bulanan = $lababersih_bulanan;
        $responseData->suku_bunga_tahunan = $bunga_tahunan;
        $responseData->co_menurun = $co_menurun;
        $responseData->co_tetap = $co_tetap;
    
        $this->output->responseCode = "00";
        $this->output->responseDesc = "Berhasil hitung simulasi pembayaran";
        $this->output->responseData = $responseData;
        
        return response()->json($this->output);   
    }

    public function insertDataNonFinansialSmeQCA(Request $request, RestClient $client) {

        if (!isset($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        $pn = (trim($request->pn));

        if (!isset($request->jawaban_qca_rm)) {
            throw new ParameterException("Parameter Data Jawaban dibutuhkan");
        }

        if (!is_array($request->jawaban_qca_rm)) {
            throw new InvalidRuleException("Format Data Jawaban Salah");
        }
        
        if (!isset($request->branch)) {
            throw new ParameterException("Parameter branch tidak valid");
        }
        $branch = (trim($request->branch));

        if (!isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        $refno = (trim($request->refno));

        if (!isset($request->level)) {
            throw new ParameterException("Parameter level tidak valid");
        }
        $level = (trim($request->level));

        if (!isset($request->tp_produk) && ($request->tp_produk == "")) {
            throw new ParameterException("Parameter tp produk tidak boleh kosong");
        }
        if (!in_array($request->tp_produk,['47','48','49','50'])) {
            throw new ParameterException("Parameter tp produk tidak valid");
        }
        $tp_produk = (trim($request->tp_produk));

        $getciflas = $this->prakarsa_repo->getPrakarsa($request->refno, ['content_datanonfinansial']);

        $clientResponse = $client->call(
            [
                "pn"=> $pn,
                "refno"=> $refno,
                "tp_produk"=> $tp_produk,
            ]
            , env('BRISPOT_MASTER_URL'), '/v1/inquiryDataNonFinansialSmeQCA'); 

        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        $nonfin = $clientResponse->responseData;
        
        if (empty($nonfin)) {
            throw new DataNotFoundException("Data non finansial tidak ditemukan");
        }
        if ($getciflas->toArray() == 0) {
            throw new DataNotFoundException("Data prakarsa tidak ditemukan");
        }
        if ($getciflas->status != '1') {
            throw new InvalidRuleException("Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.");
        }
        $datasend = new stdClass;
        $datasend->fid_cif_las = $getciflas->cif_las;
        $datasend->fid_aplikasi = $getciflas->id_aplikasi;
        $datasend->jawaban_qca_rm = array();
        // $datasend->content_datanonfinansial = $data_qca;
        $items = $request->jawaban_qca_rm;
        $cekNonFin = array();
        $validNonFin = true;
        $notifCekNonFin = "";
        
        foreach ($nonfin as $id => $val) {
            array_push($cekNonFin, strtolower($val->param_db));
        }
        foreach ($items as $id => $val) { 
                                                // echo $val->subparameter;
            if (in_array(strtolower($val['param_db']), $cekNonFin)) {
                $items_d = array();
                $items_d['param_db'] = $val['param_db'];
                $items_d['pertanyaan'] = $val['pertanyaan'];
                $items_d['nilai'] = $val['nilai'];
                $items_d['deskripsi'] = $val['deskripsi'];
                $items_d['komentar'] = $val['komentar'];
                $items_d['lampiran'] = isset($val['lampiran']) ? $val['lampiran'] :'';
                if ($val['param_db'] == '' || $val['nilai'] == '' || $val['deskripsi'] == '' || $val['komentar'] == ''){
                    throw new InvalidRuleException("Maaf, Anda harus melengkapi isiian QCA");
                }
                array_push($datasend->jawaban_qca_rm, $items_d);
            } else {
                $validNonFin = false;
                $notifCekNonFin = $val->param_db;
                break;
            }
        }

        if ($validNonFin == false) {
            throw new InvalidRuleException("Anda tidak berhak mengisi non finansial untuk survey ' . $notifCekNonFin . '.");
        }
        
        //update data brispot
        $arrdata = array(
            'upddate' => Carbon::now()->format('Y-m-d H:i:s'),
            'content_datanonfinansial' => json_encode($datasend)
        );

        $this->prakarsamediumRepo->updatePrakarsa($refno,$arrdata);
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Insert Data Non Finansial QCA RM Berhasil.';
        
        return response()->json($this->output);
    }

    public function inquiryLimitDer(Request $request, RestClient $client)
    {
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (!isset($request->refno) && empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, ['content_datapribadi']);

        if(empty($get_prakarsa)){
            throw new DataNotFoundException("Data tidak ditemukan");
        }
        
        $bidang_usaha = $get_prakarsa->content_datapribadi->bidang_usaha;

        $response = [];

        $clientResponse = $client->call(
            [
                "sekon_lbu"=> $bidang_usaha
            ]
            , env('BRISPOT_MASTER_URL'), '/v1/inquirySekonMenengah'); 
           
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }
        
        if (is_object($clientResponse->responseData)){
            
            $current_data = new stdClass;
            $current_data->id = $clientResponse->responseData->id;
            $current_data->sekon_lbu = $clientResponse->responseData->sekon_lbu;
            $current_data->deskripsi_sekon_lbu = $clientResponse->responseData->deskripsi_sekon_lbu;
            $current_data->sekon_sid = $clientResponse->responseData->sekon_sid;
            $current_data->sekon_lbut = $clientResponse->responseData->sekon_lbut;
            $current_data->qca = $clientResponse->responseData->qca;
            $current_data->limit_der = $clientResponse->responseData->limit_der;
            $current_data->warna_upper_small = $clientResponse->responseData->warna_upper_small;
            $current_data->warna_medium = $clientResponse->responseData->warna_medium;
            
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses inquiry';
        $this->output->responseData = $current_data;

        return response()->json($this->output);
    }

    public function inquiryDokumen(Request $request)
    {
        
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter reff no tidak valid.");
        }
        $refno = $request->refno;
        
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($refno, []);

        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa refno:".$request->refno." tidak ditemukan");
        }

        $path_file = !empty($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;
        $path_folder = !empty($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : NULL;
        
        $list_file = array();
        $list_agunan = new stdClass;
        
        if($path_file != null && $path_file != '') {
            
            $list_file = $path_file;
    
            if(is_dir($path_folder)) {

                $hash_link = Str::random(40);
                if(!is_dir($path_folder)) {
                    mkdir($path_folder, 0775, true);
                }
                $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
                exec($symlink_url);
                $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link . '/';

                foreach($list_file as $key => $value) {
                    if(is_dir($path_folder . $key)) {
                        
                        // $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
                        // exec($symlink_url);
                        
                        if(is_array($value)) {
                            $arr_data = [];
                            foreach($value as $idx => $val) {
                                if(is_file($path_folder . $key . '/' . $val)) {
                                    $arr_data[$idx] = $url_file . $key . '/' . $val;
                                }
                            }
                            $arr_data = array_values($arr_data);
                            $list_file->{$key} = $arr_data;
                        } else {
                            $list_file2 = $list_file->{$key};
                            foreach($list_file2 as $key2 => $value2) {
                                if(is_dir($path_folder . $key . '/' . $key2)) {
                                    if(is_array($value2)) {
                                        $arr_data = [];
                                        foreach($value2 as $idx => $val) {
                                            if(is_file($path_folder . $key . '/' . $key2 . '/' . $val)) {
                                                $arr_data[$idx] = $url_file . $key . '/' . $key2 . '/' . $val;
                                            }
                                        }
                                        $arr_data = array_values($arr_data);
                                        $list_file->{$key}->{$key2} = $arr_data;
                                    } else {
                                        $list_file3 = $list_file->{$key}->{$key2};
                                        foreach($list_file3 as $key3 => $value3) {
                                            if(is_dir($path_folder . $key . '/' . $key2 . '/' . $key3)) {
                                                if(is_array($value3)) {
                                                    $arr_data = [];
                                                    foreach($value3 as $idx => $val) {
                                                        if(is_file($path_folder . $key . '/' . $key2 . '/' . $key3 . '/' . $val)) {
                                                            $arr_data[$idx] = $url_file . $key . '/' . $key2 . '/' . $key3 . '/' . $val;
                                                        }
                                                    }
                                                    $arr_data = array_values($arr_data);
                                                    $list_file->{$key}->{$key2}->{$key3} = $arr_data;
                                                }
                                            } else {
                                                unset($list_file->{$key}->{$key2}->{$key3});
                                            }
                                        }
                                    }
                                } else {
                                    unset($list_file->{$key}->{$key2});
                                }
                            }
                        }
                    } else {
                        unset($list_file->{$key});
                    }
                }
            } else {
                $list_file = [];
            }
        }
        
        if(empty($list_file)) {
            // throw new DataNotFoundException("Data dokumen tidak ditemukan.");
            $this->output->responseCode = '00';
            $this->output->responseDesc = 'Belum ada dokumen yang diupload.';
            $this->output->responseData = null;
    
            return response()->json($this->output);
        }
    
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Inquiry Dokumentasi berhasil.';
        $this->output->responseData = $list_file;
    
        return response()->json($this->output);
    }

    public function inquiryDataKhtpk(Request $request)
    {
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (!isset($request->refno) && empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, ['content_datafinansial']);
        
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        if($get_prakarsa->content_datafinansial == null){
            throw new DataNotFoundException("Data pribadi masih kosong. silahkan mengisi data pribadi terlebih dahulu.");
        }

        $data = $get_prakarsa->content_datafinansial;
        
        $current_data = new stdClass;
        $current_data->fasilitas_pinjaman = $data->fasilitas_pinjaman;
        $current_data->fasilitas_lainnya = $data->fasilitas_lainnya;
        $current_data->total_exposure_khtpk = $data->total_exposure_khtpk;
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses inquiry data khtpk';
        $this->output->responseData = $current_data;

        return response()->json($this->output);
    }

    /**
     * 
     * Versi 2 : Controllers/V2/insertDataDebiturBadanUsahaSME
     * 
     */
    public function insertDataDebtBadanUsaha(Request $request, RestClient $client)
    {
        if (!isset($request->pn) || empty($request->pn)){
            throw new ParameterException("Parameter PN tidak boleh kosong.");
        }
        $pn = trim($request->pn);

        if (!isset($request->refno) || empty($request->refno)){
            throw new ParameterException("Parameter refno tidak boleh kosong.");
        }
        $refno = trim($request->refno);

        if (!isset($request->uid) || empty($request->uid)){
            throw new ParameterException("Parameter uid tidak boleh kosong.");
        }

        if (!isset($request->bibr) || empty($request->bibr)){
            throw new ParameterException("Parameter bibr tidak boleh kosong.");
        }
        
        if (!isset($request->branch) || empty($request->branch)){
            throw new ParameterException("Parameter branch tidak boleh kosong.");
        }
        $branch = trim($request->branch);

        $nama = $request->nama_debitur;
        $npwp = $request->npwp;

        if (!isset($request->tp_produk) || $request->tp_produk == "") {
            throw new InvalidRuleException("Tipe produk tidak ditemukan.");
        }

        $tp_produk = trim($request->tp_produk);
        
        $tp_prod_sme = ['49', '50'];

        if (in_array($tp_produk, $tp_prod_sme)) {
            $cek_piloting = $this->prakarsamediumRepo->cekParamPiloting($branch, 'sme');
            if (!$cek_piloting) {
                throw new ParameterException("Tipe produk tidak dapat dipilih. Unit kerja Anda tidak termasuk dalam unit kerja piloting");
            }
        }

        $get_jenis_pinjaman = $this->prakarsamediumRepo->getProduk(array('tp_produk' => trim($request->tp_produk)));
        $jenis_pinjaman = isset($get_jenis_pinjaman->nama_produk) ? $get_jenis_pinjaman->nama_produk : NULL;

        if (!isset($request->nama_debitur) || empty($request->nama_debitur)) {
            throw new InvalidRuleException("Nama debitur harus diisi.");
        }

        if (!empty($request->tgl_akta_awal)){
            if ($request->tgl_akta_awal != date('Y-m-d', strtotime($request->tgl_akta_awal))) {
                throw new ParameterException("Format tgl_akta_awal salah");
            }
        }
        
        if (!empty($request->tgl_akta_akhir)){
            if ($request->tgl_akta_akhir != date('Y-m-d', strtotime($request->tgl_akta_akhir))) {
                throw new ParameterException("Format tgl_akta_akhir salah");
            }
        }

        if (!in_array($request->status_badan_hukum, ['1','0'])){
            throw new InvalidRuleException("Isian status badan hukum salah.");
        }

        if (!empty($request->tgl_mulai_debitur)){
            if ($request->tgl_mulai_debitur != date('Y-m-d', strtotime($request->tgl_mulai_debitur))) {
                throw new ParameterException("Format tgl_mulai_debitur salah");
            }
        }

        if (!isset($request->jenis_rekening) && !in_array($request->jenis_rekening, ['1', '2', '3'])) {
            throw new ParameterException("Isian jenis rekening salah");
        }

        if (!isset($request->pernah_pinjam) && !in_array($request->pernah_pinjam, ['Ya', 'Tidak'])) {
            throw new ParameterException("Isian pernah pinjam bank lain salah");
        }
        
        if (!isset($request->sumber_utama) && !in_array($request->sumber_utama, ['2', '3'])) {
            throw new ParameterException("Isian sumber_utama salah");
        }
        
        if (!isset($request->sumber_utama_brinets) && !in_array($request->sumber_utama_brinets, ['00013', '00099'])) {
            throw new ParameterException("Isian sumber_utama_brinets salah");
        }
        
        if (!isset($request->federal_wh_code) && !in_array($request->federal_wh_code, ['0', '1', '2'])) {
            throw new ParameterException("Isian federal_wh_code salah");
        }
        
        if (!empty($request->tgl_kadaluarsa)){
            if ($request->tgl_kadaluarsa != date('Y-m-d', strtotime($request->tgl_kadaluarsa))) {
                throw new ParameterException("Format tgl_kadaluarsa salah");
            }
        } 
        
        if ($request->tujuan_membuka_rekening == 'ZZ' && (!isset($request->ket_buka_rekening) || (isset($request->ket_buka_rekening) && trim($request->ket_buka_rekening) == ''))) {
            throw new ParameterException("Keterangan buka rekening harus diisi.");
        }

        if (!isset($request->tujuan_membuka_rekening) && !in_array($request->tujuan_membuka_rekening, ['T2', 'T3', 'ZZ'])) {
            throw new ParameterException("Isian tujuan_membuka_rekening salah");
        }

        //G1=0s/d5jt, G2=5jts/d10jt, G3=10jts/d50jt, G4=50jts/d100jt, G5=gt100jt
        if (!isset($request->penghasilan_per_bulan) && !in_array($request->penghasilan_per_bulan, ['G1', 'G2', 'G3', 'G4', 'G5'])) {
            throw new ParameterException("Isian penghasilan per bulan salah");
        }
        
         //G1=0s/d5jt, G2=5jts/d10jt, G3=10jts/d50jt, G4=50jts/d100jt, G5=gt100jt
        if (!isset($request->omzet_per_bulan) && !in_array($request->omzet_per_bulan, ['U1', 'U2', 'U3', 'U4', 'U5'])) {
            throw new ParameterException("Isian penghasilan per bulan salah");
        }

        $hist = "0";
        if ($request->pernah_pinjam == "Ya") {
            $hist = "1";
        }

        if (!isset($request->fixed_line) && !empty($request->fixed_line)) {
            throw new InvalidRuleException("Nomor fixed line salah atau tidak boleh kosong.");
        }

        if (!isset($request->flag_postfin)){
            throw new InvalidRuleException("Update aplikasi BRISPOT dengan versi yang terbaru.");
        }
        
        if (!in_array($request->flag_postfin, ['0', '1']) && $request->tp_produk == "5") {
            throw new InvalidRuleException("Flag postfin harus diisi untuk tipe produk 5.");
        }

        if(in_array($tp_produk, ['47', '48', '49', '50'])){
            $supplier_utama = [];
            $pelanggan_utama = [];
            $daftar_perusahaan_terafiliasi = [];
            $supplier_utama = $request->supplier_utama;
            $pelanggan_utama = $request->pelanggan_utama;
            $daftar_perusahaan_terafiliasi = $request->daftar_perusahaan_terafiliasi;
        }

        if(in_array($tp_produk, ['49', '50'])){
            $arr_currentBalance = [];
            $arr_hitung_plafon_ke_total_eksposure = [];
            $jml_currentBalance = 0;
            $fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya);
        }

        // get data prakarsa
        $getciflas = $this->prakarsamediumRepo->getPrakarsa($request->refno, [
            "content_datapribadi",  
            "content_dataagunan", 
            "content_datarekomendasi",
        ]
        );
        $ciflas = $getciflas->cif_las;
        $id_aplikasi = $getciflas->id_aplikasi;
        $kategori_nasabah = $getciflas->kategori_nasabah;

        if($tp_produk == '5' && ($kategori_nasabah == '' || $kategori_nasabah == '0' || $kategori_nasabah == null) && $request->flag_postfin == '0'){
            throw new InvalidRuleException('Untuk prakarsa baru non Postfin silakan menggunakan produk SME < 1 M atau SME 1 - 5 M.');
        }

        $bidangUsaha = $client->call( ["sekon_lbu"=> $request->bidang_usaha], env('BRISPOT_MASTER_URL'), '/v1/inquirySekonMenengah'); 

        if (isset($bidangUsaha->responseCode) && $bidangUsaha->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        if (isset($bidangUsaha->responseData) && is_object($bidangUsaha->responseData)){
            $current_data = new stdClass;
            $current_data->sekon_sid = $bidangUsaha->responseData->sekon_sid;
        }

        $cekProduk = $client->call([ "tp_produk"=> $request->tp_produk ], env('BRISPOT_MASTER_URL'), '/v1/inquiryProdukPinjaman'); 

        if (isset($cekProduk->responseCode) && $cekProduk->responseCode != "00") {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        if (isset($cekProduk->responseData) && is_object($cekProduk->responseData)){
            $data_produk = new stdClass;
            $data_produk->nama_produk = $cekProduk->responseData->nama_produk;
            $data_produk->segmen = $cekProduk->responseData->segmen;
        }

        $ceknik = true;
        $namabrinets = "";
        $kodeukerbrinets = "";
        $cekdbnik = $this->prakarsamediumRepo->cekNikBrinets($request->refno, $npwp);

        if (empty($cekdbnik)) {
            // inquiry nik las
            $clientResponse = $client->call( ["nik"=> $npwp], env('BRISPOT_EKSTERNAL_URL'), '/las/inquiryNIK');

            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new DataNotFoundException($clientResponse->responseDesc);
            }
            if ($clientResponse != false) {
                if (isset($clientResponse->responseCode) && $clientResponse->responseCode == '01' || false) {
                    if (isset($clientResponse->responseData[0]->NIK) && isset($clientResponse->responseData[0]->NAMA) && isset($clientResponse->responseData[0]->UKER_ASAL)) {
                        similar_text(strtolower(trim($clientResponse->responseData[0]->NAMA)), strtolower($nama), $persensimilar);
                        if (trim($clientResponse->responseData[0]->NIK) == $npwp && $persensimilar >= 80) {
                            $this->prakarsamediumRepo->insertCekBrinets($refno, $npwp, json_encode($clientResponse->responseData[0]));
                        } else {
                            $namabrinets = trim($clientResponse->responseData[0]->NAMA);
                            $kodeukerbrinets = trim($clientResponse->responseData[0]->UKER_ASAL);
                            $ceknik = false;
                        }
                    }
                }
            } else {
                $kodeukerbrinets = "(Gagal inquiry NIK ke LAS)";
                $ceknik = false;
            }
        } else {
            if ($cekdbnik->data != '') {
                $datarek = json_decode($cekdbnik->data);
                if (isset($datarek->NIK) && isset($datarek->NAMA)) {
                    similar_text(strtolower(trim($datarek->NAMA)), strtolower($nama), $persensimilar);
                    if (trim($datarek->NIK) != $npwp || $persensimilar < 80) {
                        // inquiry nik las
                        $clientResponse = $client->call([ "nik"=> $npwp ], env('BRISPOT_EKSTERNAL_URL'), '/las/inquiryNIK');

                        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                            throw new DataNotFoundException($clientResponse->responseDesc);
                        }
                        
                        if ($clientResponse != false) {
                            if (isset($clientResponse->responseCode) && $clientResponse->responseCode == '01' || false) {
                                if (isset($clientResponse->responseData[0]->NIK) && isset($clientResponse->responseData[0]->NAMA) && isset($clientResponse->responseData[0]->UKER_ASAL)) {
                                    similar_text(strtolower(trim($clientResponse->responseData[0]->NAMA)), strtolower($nama), $persensimilar);
                                    if (trim($clientResponse->responseData[0]->NIK) == $npwp && $persensimilar >= 80) {                                        
                                        $this->prakarsamediumRepo->deleteCekBrinets($refno);
                                        $this->prakarsamediumRepo->insertCekBrinets($refno, $npwp, json_encode($clientResponse->responseData[0]));
                                    } else {
                                        $namabrinets = trim($clientResponse->responseData[0]->NAMA);
                                        $kodeukerbrinets = trim($clientResponse->responseData[0]->UKER_ASAL);
                                        $ceknik = false;
                                    }
                                }
                            }
                        } else {
                            $kodeukerbrinets = "(Gagal inquiry NIK ke LAS)";
                            $ceknik = false;
                        }
                    }
                }
            }
        }

        if ($ceknik == false) {
            $getbranch = $this->prakarsamediumRepo->getBranch((int) $kodeukerbrinets);
            $branchname = (count($getbranch) > 0 ? '-' . $getbranch->BRDESC : '');
            throw new DataNotFoundException('NPWP ' . $npwp . ' yang diusulkan terdaftar atas nama ' . $namabrinets . ', nasabah unit kerja ' . $kodeukerbrinets . ' Unit ' . $branchname . ', silahkan input data sesuai dengan identitas yang berlaku dan lakukan pengecekan pada inputan BRISPOT/Brinets apabila ditemukan ada kesalahan lakukan maintain sesuai dengan data NPWP terbaru.');
        }

        if (empty($getciflas)) {
            throw new DataNotFoundException("Data prakarsa tidak ditemukan.");
        }

        if ($getciflas->status != '1') {
            throw new InvalidRuleException("Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.");
        }

        $content_datapribadi_host = new stdClass;
        $content_datarekomendasi_host = new stdClass;
        $content_dataagunan_host = new stdClass;
        $datafinansial = new stdClass;
        $cek_pengurus = false;

        if (isset($getciflas->content_datarekomendasi)) {
            $content_datarekomendasi_host = json_decode($getciflas->content_datarekomendasi);
        }
        if (isset($getciflas->content_dataagunan)) {
            $content_dataagunan_host = $getciflas->content_dataagunan;
        }

        if (isset($getciflas->content_datapribadi)) {
            $content_datapribadi_host = $getciflas->content_datapribadi;
        }
    
        if (isset($content_datapribadi_host->pengurus)) {
            $cek_pengurus = true;
            $pengurus = $content_datapribadi_host->pengurus;
        }

        if (isset($getciflas->content_datafinansial)) {
            $datafinansial = $getciflas->content_datafinansial;
        }

        //cek prescreening
        $arrjk = array('l' => '1', 'p' => '2', 'L' => '1', 'P' => '2');
        $jkdeb = isset($arrjk[$request->bentuk_badan_usaha]) ? $arrjk[$request->bentuk_badan_usaha] : '';
        $tgldeb = (isset($request->tgl_lahir) ? trim($request->tgl_mulai_debitur) : '');
        
        $cekpre = true;
        $msgcekpre = "";
        $aliasdeb = $request->nama_debitur_1;

        $this->prakarsamediumRepo->deleteCekBrinets($refno);

        // insert data debitur
        $clientResponse = $client->call(
            [
                "npwp"=> $npwp,
                "fid_cif_las"=> $ciflas,
                "id_aplikasi"=> !empty($id_aplikasi) ? $id_aplikasi : '0',
                "tp_produk"=> $request->tp_produk,
                "uid"=> $request->uid,
                "suplesi"=> !empty($kategori_nasabah) ? $kategori_nasabah : '0',
                "flag_sp"=> !empty($kategori_nasabah) ? $kategori_nasabah : '0',
                "no_rekening"=> !empty($kategori_nasabah) ? $getciflas->norek_pinjaman : '',
                "kode_cabang"=> $request->branch,
                "nama_debitur"=> $request->nama_debitur,
                "nama_alias"=> $request->nama_debitur,
                "gelar"=> $request->gelar,
                "bidang_usaha"=> $current_data->sekon_sid,
                "lama_bekerja"=> $request->lama_bekerja,
                "tempat_akta_awal"=> $request->tempat_akta_awal,
                "no_akta_awal"=> $request->no_akta_awal,
                "tgl_akta_awal"=> !empty($request->tgl_akta_awal) ? Carbon::parse($request->tgl_akta_akhir)->format('dmY') : Carbon::now()->format('dmY'),
                "no_akta_akhir"=> $request->no_akta_akhir,
                "tgl_akta_akhir"=> !empty($request->tgl_akta_akhir) ? Carbon::parse($request->tgl_akta_akhir)->format('dmY') : Carbon::now()->format('dmY'),
                "status_badan_hukum"=> $request->status_badan_hukum,
                "alamat"=> $request->alamat,
                "kewarganegaraa"=> $request->kewarganegaraa,
                "rt"=> $request->rt,
                "rw"=> $request->rw,
                "kelurahan"=> $request->kelurahan,
                "kecamatan"=> $request->kecamatan,
                "kabupaten"=> $request->kabupaten,
                "dati2"=> $request->dati2,
                "provinsi"=> $request->provinsi,
                "kode_pos"=> $request->kode_pos,
                "no_hp"=> $request->no_hp,
                "fixed_line"=> $request->fixed_line,
                "tgl_mulai_debitur"=> !empty($request->tgl_mulai_debitur) ? Carbon::parse($request->tgl_mulai_debitur)->format('dmY') : Carbon::now()->format('dmY'),
                "jenis_rekening"=> $request->jenis_rekening,
                "nama_bank_lain"=> $request->nama_bank_lain,
                "pernah_pinjam"=> $request->pernah_pinjam,
                "sumber_utama"=> $request->sumber_utama,
                "alamat_usaha"=> $request->alamat_usaha,
                "kelurahan_usaha"=> $request->kelurahan_usaha,
                "kecamatan_usaha"=> $request->kecamatan_usaha,
                "kodepos_usaha"=> $request->kodepos_usaha,
                "kota_usaha"=> $request->kota_usaha,
                "dati2_usaha"=> $request->dati2_usaha,
                "kode_pos_usaha"=> $request->kode_pos_usaha,
                "provinsi_usaha"=> $request->provinsi_usaha,
                "pendapatan_per_bulan"=> $request->pendapatan_per_bulan,
                "omzet"=> $request->omzet,
                "status_gelar"=> $request->status_gelar,
                "keterangan_status_gelar"=> $request->keterangan_status_gelar,
                "federal_wh_code"=> $request->federal_wh_code,
                "customer_type"=> $request->customer_type,
                "sub_customer_type"=> $request->sub_customer_type,
                "hub_debitur"=> $request->hub_debitur,
                "segmen_bisnis_bri"=> $data_produk->nama_produk,
                "transaksi_normal_harian"=> $request->transaksi_normal_harian,
                "jenis_badan_usaha"=> $request->jenis_badan_usaha,
                "bidang_usaha_brinets"=> $request->bidang_usaha_brinets,
                "bentuk_badan_usaha"=> $request->bentuk_badan_usaha,
                "tipe_id"=> 'SI',
                "no_id"=> $request->no_id,
                "tgl_kadaluarsa"=> !empty($request->tgl_kadaluarsa) ? Carbon::parse($request->tgl_kadaluarsa)->format('dmY') : Carbon::now()->format('dmY'),
                "fax"=> $request->fax,
                "tujuan_membuka_rekening"=> $request->tujuan_membuka_rekening,
                "penghasilan_per_bulan"=> $request->penghasilan_per_bulan,
                "omzet_per_bulan"=> $request->omzet_per_bulan,
                "transaksi_normal_harian_brinets"=> $request->transaksi_normal_harian_brinets,
                "sumber_utama_brinets"=> $request->sumber_utama_brinets,
                "email"=> $request->email,
                "ket_buka_rekening"=> $request->ket_buka_rekening,
                "resident_flag"=> 'Y',
                "golongan_debitur_lbu"=> '886',
                "latitude"=> $request->latitude,
                "longitude"=> $request->longitude,
                "desc"=> $request->desc,
                "bidang_usaha_lain"=> $request->bidang_usaha_lain,
                "tempat_akta_awal_dikeluarkan"=> $request->tempat_akta_awal,
                "alamat_debitur"=> $request->alamat,
                "lokasi_dati_2"=> $request->dati2,
                "propinsi_usaha"=> $request->provinsi_usaha,
                "propinsi"=> $request->provinsi,
                "telepon_fixed_line"=> $request->fixed_line,
                "negara_domisili"=> 'ID',
                "golongan_debitur"=> '916',
                "tanggal_mulai_sebagai_debitur"=> !empty($request->tgl_mulai_debitur) ? Carbon::parse($request->tgl_mulai_debitur)->format('dmY') : Carbon::now()->format('dmY'),
                "jenis_rekening_dimiliki_debitur_pd_bank_lain"=> $request->jenis_rekening,
                "apakah_pernah_pinjam_di_bank_lain"=> $request->pernah_pinjam,
                "kewarganegaraan_negara_asal"=> 'ID',
                "alamat_kerja"=> $request->alamat_usaha,
                "pendapatan_perbulan"=> $request->pendapatan_per_bulan,
                "omzet_usaha_pertahun"=> $request->omzet,
                "kategori_portofolio"=> '100',
                "hub_debitur_dg_bank"=> $request->hub_debitur,
                "flag_postfin"=> isset($request->flag_postfin) && !empty($request->flag_postfin) ? $request->flag_postfin : '',
            ]
            , env('BRISPOT_EKSTERNAL_URL'), '/las/insertDataDebiturBadanUsaha');

        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
            throw new DataNotFoundException($clientResponse->responseDesc);
        }

        // surveydatapribadi
        $datapribadi = new stdClass;
        $datapribadi->npwp = (isset($npwp) ? trim($npwp) : '');
        $datapribadi->title = "";
        $datapribadi->nama_debitur = (isset($request->nama_debitur) ? trim($request->nama_debitur) : '');
        $datapribadi->alias = (isset($request->alias) ? trim($request->alias) : '');
        $datapribadi->gelar = (isset($request->gelar) ? trim($request->gelar) : '');
        $datapribadi->bidang_usaha = (isset($current_data->sekon_sid) ? trim($current_data->sekon_sid) : '');
        $datapribadi->lama_bekerja = (isset($request->lama_bekerja) ? trim($request->lama_bekerja) : '');
        $datapribadi->tempat_akta_awal = (isset($request->tempat_akta_awal) ? trim($request->tempat_akta_awal) : '');
        $datapribadi->no_akta_awal = (isset($request->no_akta_awal) ? trim($request->no_akta_awal) : '');
        $datapribadi->tgl_akta_awal = (isset($request->tgl_akta_awal) ? trim($request->tgl_akta_awal) : '');
        $datapribadi->no_akta_akhir = (isset($request->no_akta_akhir) ? trim($request->no_akta_akhir) : '');
        $datapribadi->tgl_akta_akhir = (isset($request->tgl_akta_akhir) ? trim($request->tgl_akta_akhir) : '');
        $datapribadi->status_badan_hukum = (isset($request->status_badan_hukum) ? trim($request->status_badan_hukum) : '');
        $datapribadi->alamat = (isset($request->alamat) ? trim($request->alamat) : '');
        $datapribadi->rt = (isset($request->rt) ? trim($request->rt) : '');
        $datapribadi->rw = (isset($request->rw) ? trim($request->rw) : '');
        $datapribadi->kelurahan = (isset($request->kelurahan) ? trim($request->kelurahan) : '');
        $datapribadi->kecamatan = (isset($request->kecamatan) ? trim($request->kecamatan) : '');
        $datapribadi->kabupaten = (isset($request->kabupaten) ? trim($request->kabupaten) : '');
        $datapribadi->kode_pos = (isset($request->kode_pos) ? trim($request->kode_pos) : '');
        $datapribadi->kode_dati = (isset($request->dati2) ? trim($request->dati2) : '');
        $datapribadi->provinsi = (isset($request->provinsi) ? trim($request->provinsi) : '');
        $datapribadi->no_hp = (isset($request->no_hp) ? trim($request->no_hp) : '');
        $datapribadi->email = (isset($request->email) ? trim($request->email) : '');
        $datapribadi->negara_domisili = "ID";
        $datapribadi->golongan_debitur = "916";
        $datapribadi->tgl_mulai_usaha = ($request->tgl_mulai_debitur != '' ? $request->tgl_mulai_debitur : '');
        $datapribadi->jenis_rekening = (isset($request->jenis_rekening) ? trim($request->jenis_rekening) : '');
        $datapribadi->nama_bank_lain = (isset($request->nama_bank_lain) ? trim($request->nama_bank_lain) : '');
        $datapribadi->pernah_pinjam = (isset($request->pernah_pinjam) ? trim($request->pernah_pinjam) : '');
        $datapribadi->sumber_utama = (isset($request->sumber_utama) ? trim($request->sumber_utama) : '');
        $datapribadi->kewarganegaraan_negara_asal = "Indonesia";
        $datapribadi->alamat_usaha = (isset($request->alamat_usaha) ? trim($request->alamat_usaha) : '');
        $datapribadi->golongan_debitur_lbu = "886";
        $datapribadi->pendapatan_per_bulan = (isset($request->pendapatan_per_bulan) ? trim($request->pendapatan_per_bulan) : '');
        $datapribadi->omzet = (isset($request->omzet) ? trim($request->omzet) : '');
        $datapribadi->status_gelar = (isset($request->status_gelar) ? trim($request->status_gelar) : '');
        $datapribadi->keterangan_status_gelar = (isset($request->keterangan_status_gelar) ? trim($request->keterangan_status_gelar) : '');
        $datapribadi->kategori_portofolio = "100";
        $datapribadi->resident_flag = "Y";
        $datapribadi->federal_wh_code = (isset($request->federal_wh_code) ? trim($request->federal_wh_code) : '');
        $datapribadi->customer_type = (isset($request->customer_type) ? trim($request->customer_type) : '');
        $datapribadi->sub_customer_type = (isset($request->sub_customer_type) ? trim($request->sub_customer_type) : '');
        $datapribadi->hub_debitur = (isset($request->hub_debitur) ? trim($request->hub_debitur) : '');
        $datapribadi->segmen_bisnis_bri = $data_produk->nama_produk;
        $datapribadi->transaksi_normal_harian = (isset($request->transaksi_normal_harian) ? trim($request->transaksi_normal_harian) : '');
        $datapribadi->jenis_badan_usaha = (isset($request->jenis_badan_usaha) ? trim($request->jenis_badan_usaha) : '');
        $datapribadi->bidang_usaha_brinets = (isset($request->bidang_usaha_brinets) ? trim($request->bidang_usaha_brinets) : '');
        $datapribadi->bentuk_badan_usaha = (isset($request->bentuk_badan_usaha) ? trim($request->bentuk_badan_usaha) : '');
        $datapribadi->tipe_id = (isset($request->tipe_id) ? trim($request->tipe_id) : '');
        $datapribadi->no_id = (isset($request->no_id) ? trim($request->no_id) : '');
        $datapribadi->tgl_kadaluarsa = (isset($request->tgl_kadaluarsa) ? trim($request->tgl_kadaluarsa) : '');
        $datapribadi->fax = (isset($request->fax) ? trim($request->fax) : '');
        $datapribadi->tujuan_membuka_rekening = (isset($request->tujuan_membuka_rekening) ? trim($request->tujuan_membuka_rekening) : '');
        $datapribadi->penghasilan_per_bulan = (isset($request->penghasilan_per_bulan) ? trim($request->penghasilan_per_bulan) : '');
        $datapribadi->omzet_per_bulan = (isset($request->omzet_per_bulan) ? trim($request->omzet_per_bulan) : '');
        $datapribadi->transaksi_normal_harian = (isset($request->transaksi_normal_harian) ? trim($request->transaksi_normal_harian) : '');
        $datapribadi->sumber_utama_brinets = (isset($request->sumber_utama_brinets) ? trim($request->sumber_utama_brinets) : '');

        $datapribadi->alamat_domisili = (isset($request->alamat_domisili) ? trim($request->alamat_domisili . ' ' . $request->rt_domisili . ' ' . $request->rw_domisili) : '');
        $datapribadi->kodepos_domisili = (isset($request->kodepos_domisili) ? trim($request->kodepos_domisili) : '');
        $datapribadi->kelurahan_domisili = (isset($request->kelurahan_domisili) ? trim($request->kelurahan_domisili) : '');
        $datapribadi->kecamatan_domisili = (isset($request->kecamatan_domisili) ? trim($request->kecamatan_domisili) : '');
        $datapribadi->kota_domisili = (isset($request->kota_domisili) ? trim($request->kota_domisili) : '');
        $datapribadi->propinsi_domisili = (isset($request->provinsi_domisili) ? trim($request->provinsi_domisili) : '');
        $datapribadi->kelurahan_usaha = (isset($request->kelurahan_usaha) ? trim($request->kelurahan_usaha) : '');
        $datapribadi->kecamatan_usaha = (isset($request->kecamatan_usaha) ? trim($request->kecamatan_usaha) : '');
        $datapribadi->kota_usaha = (isset($request->kota_usaha) ? trim($request->kota_usaha) : '');
        $datapribadi->provinsi_usaha = (isset($request->provinsi_usaha) ? trim($request->provinsi_usaha) : '');
        $datapribadi->kodepos_usaha = (isset($request->kodepos_usaha) ? trim($request->kodepos_usaha) : '');
        $datapribadi->kode_dati_2_usaha = (isset($request->dati2_usaha) ? trim($request->dati2_usaha) : '');
        $datapribadi->expired_ktp = "31122099";
        $datapribadi->agama = '';
        $datapribadi->ket_agama = '';
        $datapribadi->tgl_lahir = '';
        $datapribadi->tempat_lahir = '';
        $datapribadi->nama_pasangan = '';
        $datapribadi->tgl_lahir_pasangan = '';
        $datapribadi->no_ktp_pasangan = '';
        $datapribadi->status_perkawinan = '';
        $datapribadi->perjanjian_pisah_harta = '';
        $datapribadi->jumlah_tanggungan = '';
        $datapribadi->jenis_kelamin = '';
        $datapribadi->nama_ibu = '';
        $datapribadi->lama_menetap = '';
        $datapribadi->tgl_mulai_usaha = '';
        $datapribadi->kepemilikan_tempat_tinggal = '';
        $datapribadi->nama_kelg = "";
        $datapribadi->telp_kelg = (isset($request->fixed_line) ? trim($request->fixed_line) : '');
        $datapribadi->pekerjaan_debitur = '';
        $datapribadi->nama_perusahaan = '';
        $datapribadi->jenis_pekerjaan = '';
        $datapribadi->ket_pekerjaan = '';
        $datapribadi->jabatan = '';
        $datapribadi->ket_buka_rekening = '';
        $datapribadi->flag_postfin = (isset($request->flag_postfin) ? trim($request->flag_postfin) : '');
        $latitude = (isset($request->latitude) ? trim($request->latitude) : '');
        $longitude = (isset($request->longitude) ? trim($request->longitude) : '');
        $datapribadi->latitude = $latitude;
        $datapribadi->longitude = $longitude;
        $datapribadi->desc_ll = (isset($request->desc) ? trim($request->desc) : '');
        $datapribadi->plafon_outstanding = (isset($request->plafon_outstanding) ? $request->plafon_outstanding : '');
        $datapribadi->badan_usaha_ojk = (isset($request->badan_usaha_ojk) ? $request->badan_usaha_ojk : '');
        $datapribadi->bidang_usaha_ket = (isset($request->bidang_usaha_ket) ? $request->bidang_usaha_ket : '');
        $datapribadi->bidang_usaha_ojk = (isset($request->bidang_usaha_ojk) ? $request->bidang_usaha_ojk : '');
        $datapribadi->bidang_usaha_ojk_ket = (isset($request->bidang_usaha_ojk_ket) ? $request->bidang_usaha_ojk_ket : '');
        $datapribadi->jenis_badan_usaha_ket = (isset($request->jenis_badan_usaha_ket) ? $request->jenis_badan_usaha_ket : '');
        $datapribadi->golongan_debitur_lbu_ket = (isset($request->golongan_debitur_lbu_ket) ? $request->golongan_debitur_lbu_ket : '');
        $datapribadi->golongan_debitur_ket = (isset($request->golongan_debitur_ket) ? $request->golongan_debitur_ket : '');
        $datapribadi->hub_debitur_ket = (isset($request->hub_debitur_ket) ? $request->hub_debitur_ket : '');
        $datapribadi->bidang_usaha_brinets_ket = (isset($request->bidang_usaha_brinets_ket) ? $request->bidang_usaha_brinets_ket : '');
        $datapribadi->customer_type_ket = (isset($request->customer_type_ket) ? $request->customer_type_ket : '');
        $datapribadi->sub_customer_type_ket = (isset($request->sub_customer_type_ket) ? $request->sub_customer_type_ket : '');
        $datapribadi->nama_bank_lain_ket = (isset($request->nama_bank_lain_ket) ? $request->nama_bank_lain_ket : '');
        $datapribadi->tp_produk_ket = (isset($request->tp_produk_ket) ? $request->tp_produk_ket : '');

        if (in_array($request->tp_produk, ['47','48','49','50'])) {
            if (!empty($supplier_utama)) {
                $datapribadi->supplier_utama = $supplier_utama;
            }
            if (!empty($pelanggan_utama)){
                $datapribadi->pelanggan_utama = $pelanggan_utama;
            }
            if (!empty($daftar_perusahaan_terafiliasi)){
                $datapribadi->daftar_perusahaan_terafiliasi = $daftar_perusahaan_terafiliasi;
            }
        } 

        //LOAD DATA PENJAMIN
        if ($cek_pengurus == true) {
            $datapribadi->pengurus = ($pengurus);
        }

        if (in_array($request->tp_produk, ['49','50'])) {
            $datafinansial->total_exposure_khtpk = (isset($request->total_exposure_khtpk) ? $request->total_exposure_khtpk : '0');
            $datafinansial->fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $datafinansial->fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya);
        }

        if(in_array($tp_produk, ['47','48']) && in_array($kategori_nasabah, ["1", "2", "4"])){
            $datafinansial->total_exposure_khtpk = (isset($request->total_exposure_khtpk) ? $request->total_exposure_khtpk : '0');
            $datafinansial->total_exposure_sebelum_putusan = (isset($request->total_exposure_sebelum_putusan) ? $request->total_exposure_sebelum_putusan : '0');
            $datafinansial->fasilitas_pinjaman = new FasilitasPinjamanCollection($request->fasilitas_pinjaman);
            $datafinansial->fasilitas_lainnya = new FasilitasLainnyaCollection($request->fasilitas_lainnya); 
        }

        $ket_segmen = 'ritel';
        if($tp_produk == '50'){
            $ket_segmen = 'menengah';
        }

        $idkredit = 0;

        if (isset($clientResponse->responseData[0])) {
            if (in_array($kategori_nasabah, array("1", "2", "3", "4"))) { // 1 = perpanjangan, 2 = suplesi, 3 = restruk 4 = deplesi
                $idkredit = isset($clientResponse->responseData[0]->ID_KREDIT) ? trim($clientResponse->responseData[0]->ID_KREDIT) : '0';
                $idaplikasi = isset($clientResponse->responseData[0]->ID_APLIKASI) ? trim($clientResponse->responseData[0]->ID_APLIKASI) : '0';

                if (!empty($content_datarekomendasi_host)) {
                    foreach ($content_datarekomendasi_host as $key => $row_datarekomendasi) {
                        $row_datarekomendasi = (array) $row_datarekomendasi;
                        if ($key == "1") {
                            $row_datarekomendasi['id_kredit'] = $idkredit;
                            $content_temp[1] = $row_datarekomendasi;
                        }

                        if ($key == "rekomenAdk") {
                            foreach ($row_datarekomendasi as $row_rekomenAdk) {
                                $row_rekomenAdk = (array) $row_rekomenAdk;
                                $row_rekomenAdk['id_kredit'] = $idkredit;
                                $content_temp['rekomenAdk'][$idkredit] = $row_rekomenAdk;
                            }
                        }
                    }
                }

                $content_datarekomendasi_host = $content_temp;
                if (!empty((array) $content_dataagunan_host)) {
                    $content_dataagunan_host = $content_dataagunan_host->toArray();
                    foreach ($content_dataagunan_host as $content_index => $content) {
                        $content_dataagunan_host[$content_index]['fid_kredit'] = $idkredit;
                        $content_dataagunan_host[$content_index]['id_kredit'] = $idkredit;
                        $content_dataagunan_host[$content_index]['fid_aplikasi'] = $idaplikasi;
                        $content_dataagunan_host[$content_index]['id_aplikasi'] = $idaplikasi;
                    }

                    $content_dataagunan_host = json_encode($content_dataagunan_host);
                    // $content_dataagunan_host = preg_replace('/"fid_kredit":""/i', '"fid_kredit":"' . $idkredit . '"', preg_replace('/"id_kredit":""/i', '"id_kredit":"' . $idkredit . '"', $content_dataagunan_host)); //ganti id_kredit yang kosong
                    // $content_dataagunan_host = preg_replace('/"fid_aplikasi":""/i', '"fid_aplikasi":"' . $idaplikasi . '"', preg_replace('/"id_aplikasi":""/i', '"id_aplikasi":"' . $idaplikasi . '"', $content_dataagunan_host)); //ganti id_kredit yang kosong
                } else {
                    $content_dataagunan_host = json_encode((array) $content_dataagunan_host);
                }

            } else {
                if ($clientResponse->responseData[0]->ID_APLIKASI == $getciflas->id_aplikasi) {
                    $idkredit = $getciflas->id_kredit;
                }
            }
        }
        
        $arrdata = array(
            'upddate' => Carbon::now()->format('Y-m-d H:i:s'),
            'uid_ao' => trim($request->uid),
            'bibr' => trim($request->bibr),
            'content_datapribadi' => json_encode($datapribadi),
            'content_datafinansial' => json_encode($datafinansial),
            'nik' => $npwp,
            'nama_debitur' => trim($request->nama_debitur),
            'tp_produk' => trim($request->tp_produk),
            'segmen' => trim($ket_segmen),
            'jenis_pinjaman' => $data_produk->nama_produk,
            'cif_las' => (isset($clientResponse->responseData[0]) ? $clientResponse->responseData[0]->CIF_LAS : '0'),
            'id_aplikasi' => (isset($clientResponse->responseData[0]) ? $clientResponse->responseData[0]->ID_APLIKASI : '0'),
            'id_kredit' => $idkredit,
            'email' => (isset($request->email) ? trim($request->email) : '')
        );

        if (in_array($kategori_nasabah, array("1", "2", "3", "4"))) { // 1 = perpanjangan, 2 = suplesi, 3 = restruk, 4 = deplesi
            $arrdata['content_datarekomendasi'] = json_encode($content_datarekomendasi_host);
            $arrdata['content_dataagunan'] = $content_dataagunan_host;
        }

        $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);

        $response = [];
        foreach ($clientResponse->responseData as $data) {
            $new_response = new stdClass;
            $new_response->CIF_LAS = $data->CIF_LAS;
            $new_response->ID_APLIKASI = $data->ID_APLIKASI;
            $new_response->FLAG_POSTFIN = $data->FLAG_POSTFIN;
            $response[] = $new_response;
        }
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data badan usaha';
        $this->output->responseData = !empty($response) ? $response : [];

        return response()->json($this->output);
    }
    
    public function hitungNetTradingAset(Request $request) {
        
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak ditemukan");
        }
       
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid.");
        }
        
        if (!isset($request->sds) || empty($request->sds)) {
            throw new ParameterException("Parameter sds tidak valid.");
        }

        if ($request->sds < 30) {
            throw new ParameterException("Nilai sds minimal 30%");
        }
        $sds = $request->sds;
        
        if (!isset($request->high_nta) || empty($request->high_nta)) {
            throw new ParameterException("Parameter high_nta tidak valid.");
        }
        $high_nta = $request->high_nta;
        
        if (!isset($request->normal_nta) || empty($request->normal_nta)) {
            throw new ParameterException("Parameter normal_nta tidak valid.");
        }
        $normal_nta = $request->normal_nta;
        
        if (!isset($request->project_revenue) || empty($request->project_revenue)) {
            throw new ParameterException("Parameter project_revenue tidak valid.");
        }
        $project_revenue = $request->project_revenue;
        
        if (!isset($request->revenue) || empty($request->revenue)) {
            throw new ParameterException("Parameter revenue tidak valid.");
        }
        $revenue = $request->revenue;
    
        $self_fund = 1 - ($sds/100);
        
        $kebutuhan_modal_kerja = ($self_fund) * ($high_nta - $normal_nta) * ($project_revenue / $revenue);

        $responseData = new stdClass;
        $responseData->revenue = $revenue;
        $responseData->kebutuhan_modal_kerja = (string)$kebutuhan_modal_kerja;
        

        $this->output->responseCode = "00";
        $this->output->responseDesc = "Berhasil hitung pendekatan Net Trading Aset";
        $this->output->responseData = $responseData;
    
        return response()->json($this->output);
    }

    public function hitungPendekatanSpreadsheet(Request $request) {
        
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak ditemukan");
        }
       
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid.");
        }
        
        if (!isset($request->piutang_usaha) || $request->piutang_usaha == '') {
            throw new ParameterException("Parameter piutang_usaha tidak valid.");
        }

        $piutang_usaha = $request->piutang_usaha;
        
        if (!isset($request->hutang_usaha) || $request->hutang_usaha == '') {
            throw new ParameterException("Parameter hutang_usaha tidak valid.");
        }
        $hutang_usaha = $request->hutang_usaha;
        
        if (!isset($request->persediaan) || $request->persediaan == '') {
            throw new ParameterException("Parameter high_nta_inventory tidak valid.");
        }
        $persediaan = $request->persediaan;
        
        if (!isset($request->kas_periode_lalu) ) {
            throw new ParameterException("Parameter kas_periode_lalu tidak valid.");
        }
        $kas_periode_lalu = $request->kas_periode_lalu;
        
        if (!isset($request->kmk_outstanding) || $request->kmk_outstanding == '') {
            throw new ParameterException("Parameter kmk_outstanding tidak valid.");
        }
        $kmk_outstanding = $request->kmk_outstanding;

        
        $hasil = (($piutang_usaha + $persediaan) - $hutang_usaha) - $kas_periode_lalu + $kmk_outstanding;
        

        $responseData = new stdClass;
        
        $responseData->total_kebutuhan_modal_kerja = (string)$hasil;
    
        $this->output->responseCode = "00";
        $this->output->responseDesc = "Berhasil hitung pendekatan pendekatan spreadsheet";
        $this->output->responseData = $responseData;
    
        return response()->json($this->output);
    }

    public function prescreeningMediumUpperSmall(Request $request, RestClient $client, MonolithicClient $mono_client)
    {
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter PN tidak valid");
        }
        $pn = trim($request->pn);
    
        if (!isset($request->branch) || empty($request->branch)) {
            throw new ParameterException("Parameter branch tidak valid");
        }
        $branch = trim($request->branch);

        if (!isset($request->tp_produk) || empty($request->tp_produk) || trim($request->tp_produk) == NULL) {
            throw new ParameterException("Parameter tp_produk  tidak valid");
        }
        $tp_produk = trim($request->tp_produk);
    
        if (!isset($request->bibr) || empty($request->bibr)) {
            throw new ParameterException("Parameter bibr tidak valid");
        }
        $bibr = trim($request->bibr);
    
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        $refno = trim($request->refno);
        
        if (!isset($request->nama_debitur) || empty($request->nama_debitur)) {
            throw new ParameterException("nama debitur tidak boleh kosong");
        }
        $nama = trim($request->nama_debitur);
        
        if (!isset($request->nik) || empty($request->nik)) {
            throw new ParameterException("NIK tidak boleh kosong");
        }
        $nik = trim($request->nik);

        $objsid = new stdClass;
        $objdhn = new stdClass;
        $objsicd = new stdClass;
        $arrgender = array('l' => '1', 'p' => '2');
        $msgcomplete = "";
        $company = "";
        $warningmsg = "";
        $msgpre = false;
        $screenPs = array();
        $screenBkpm = array();
        $screenSac = array();
        
        $get_prakarsa = $this->prakarsamediumRepo->getCiflasByRefno($refno, $branch, $pn);
        if(empty($get_prakarsa)){
            throw new DataNotFoundException("Data prakarsa tidak ditemukan");
        }

        if ($get_prakarsa->status == '1') {
           
            $datapribadi = json_decode($get_prakarsa->content_datapribadi);
            $bidang_usaha = $datapribadi->bidang_usaha;

            $clientResponse = $client->call(
                [
                    "sekon_lbu"=> $bidang_usaha
                ]
                , env('BRISPOT_MASTER_URL'), '/v1/inquirySekonMenengah'); 
            
            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new DataNotFoundException($clientResponse->responseDesc);
            }

            if($tp_produk == '49'){
                $kode_warna = $clientResponse->responseData->warna_medium;

            } elseif($tp_produk == '50'){
                $kode_warna = $clientResponse->responseData->warna_upper_small;
            }

            switch ($kode_warna) {
                case '1':
                    $warna = "hijau";
                    break;
                case '2':
                    $warna = "hijau muda";
                    break;
                case '3':
                    $warna = "kuning";
                    break;
                default:
                    $warna = "merah";
                    break;
            }
            
            $kode_ps = $kode_warna;
            $kode_warna = $warna;
            
            $sac = $request->sac;
            
            $sac['kode_sac'] = trim($kode_ps);
            
            $pertanyaan_umum = isset($sac['pertanyaan_umum']) ? $sac['pertanyaan_umum'] : [];
            $pertanyaan_khusus = isset($sac['pertanyaan_khusus']) ? $sac['pertanyaan_khusus'] : [];
            $pertanyaan_spesifik = [];
            $sac['warna'] = $warna;
            
            $arr_umum = [];
            $arr_khusus = [];
            $arr_spesifik = [];

            $clientResponse = $client->call(
                [
                    "pn" => $pn,
                    "refno" => $refno,
                    "tp_produk" => $tp_produk
                ]
                , env('BRISPOT_MASTER_URL'), '/v1/inquiryDataSAC');
               
            if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
                throw new DataNotFoundException($clientResponse->responseDesc);
            }
           
            $getPertanyaanSAC = $clientResponse->responseData;

            if (count($getPertanyaanSAC) == 0) {
                throw new DataNotFoundException("Data tidak ditemukan");
            }
            
            foreach ($getPertanyaanSAC as $value) {
                if ($value->tipe == "umum"){
                    $list_pertanyaan = isset($sac['pertanyaan_umum']) ? $sac['pertanyaan_umum'] : [];
                    
                    $value_SAC = [
                        "pertanyaan" => $value->pertanyaan,
                        "value"      => in_array($value->pertanyaan, $list_pertanyaan) ? 1 : 0
                    ];
                    array_push($arr_umum, $value_SAC);
                } elseif ($value->tipe == "khusus" || $value->tipe == "spesifik"){
                    $list_pertanyaan = isset($sac['pertanyaan_khusus']) ? $sac['pertanyaan_khusus']: [];
                    $value_SAC = [
                        "pertanyaan" => $value->pertanyaan,
                        "value"      => in_array($value->pertanyaan, $list_pertanyaan) ? 1 : 0
                    ];
                    array_push($arr_khusus, $value_SAC);
                } 
                
                
            }
            
            if (!isset($request->flag_bkpm) || (trim($request->flag_bkpm) == '') || (!in_array(trim($request->flag_bkpm), ['0', '1']))) {
                throw new ParameterException("Prescreening gagal. kode BPKM kosong/salah");
            }
            
            $flag_bkpm = trim($request->flag_bkpm);
            $dateActivity = Carbon::now()->format('Y-m-d H:i:s');
            $cekHasil = '1';
            $msgSlik = "";
            $flag_override = '0';
            $result_prescreening = '0';
            $keterangan = '';
            $keterangan_tolak = "Prakarsa tidak dapat dilanjutkan.";
            $keterangan_lolos = "Lolos pre-screening. Prakarsa dapat dilanjutkan.";
            $keterangan_override = "Lolos pre-screening. Prakarsa dapat dilanjutkan dengan mekanisme override.";
            $tolak_ps = '';
            $tolak_bkpm = '';
            $tolak_sac = '';
            $tolak_dhn = '';
            $tolak_slik = '';
            $override = '';
            $tolak_sicd = '';
            $tolak_kemendagri = '';
            $ket_slik_null = "Hasil Slik belum muncul, tunggu 10 menit lagi";
            $override_ps = "Pasar sasaran masuk kategori merah.";
            $override_sac = "Terdapat ketidaksesuaian pada SAC.";
                
                if  (($kode_ps == '4') && ($tp_produk == '49')) {
                    $flag_override = '1';
                    $result_prescreening = '1';
                    $cekHasil = '2';
                    $override .= " -Pasar sasaran masuk kategori merah";
                    $objpsdeb = new stdClass;
                    $objpsdeb->nama_debitur = $nama;
                    $objpsdeb->result = $result_prescreening;
                    $objpsdeb->kode_warna = $kode_ps;
                    $objpsdeb->warna = $warna;
                    $objpsdeb->flag_override = "Y";
                    $objps = $objpsdeb;
    
                } elseif (($kode_ps == '4') && ($tp_produk == '50')) {
                    $flag_override = '1';
                    $result_prescreening = '1';
                    $cekHasil = '2';
                    $override .= " -Pasar sasaran masuk kategori merah";
                    $objpsdeb = new stdClass;
                    $objpsdeb->nama_debitur = $nama;
                    $objpsdeb->result = $result_prescreening;
                    $objpsdeb->kode_warna = $kode_ps;
                    $objpsdeb->warna = $warna;
                    $objpsdeb->flag_override = "Y";
                    $objps = $objpsdeb;
                    
                } else {
                    $result_prescreening = '1';
                    $objpsdeb = new stdClass;
                    $objpsdeb->nama_debitur = $nama;
                    $objpsdeb->result = $result_prescreening;
                    $objpsdeb->kode_warna = $kode_ps;
                    $objpsdeb->warna = $warna;
                    $objpsdeb->flag_override = "N";
                    $objps = $objpsdeb;
    
                }
    
                $objpspers = new stdClass;
                $objpspers->debitur = $objps;
                array_push($screenPs, $objpspers);
    
                $array_ps = array(
                    'nama' => $nama,
                    'refno' => $refno,
                    'nik' => $nik,
                    'branch' => $branch,
                    'userid_ao' => $pn,
                    'data' => json_encode($objps),
                    'updated' => $dateActivity
                );
                
                $updatePrescreenPs = $this->prakarsamediumRepo->updatePrescreenPs($array_ps);
                
                if ((count($pertanyaan_umum) < 8) && ($sac['kode_sac'] < 3)) {
                    $flag_override = '1';
                    $result_prescreening = '1';
                    $cekHasil = '2';
                    $override .= " -Terdapat ketidaksesuaian pada SAC";
                    $objsacdeb = new stdClass;
                    $objsacdeb->nama_debitur = $nama;
                    $objsacdeb->result = $result_prescreening;
                    $objsacdeb->kode_warna = $sac['kode_sac'];
                    $objsacdeb->warna = $sac['warna'];
                    $objsacdeb->pertanyaan_umum = $arr_umum;
                    $objsacdeb->flag_override = "Y";
                    $objsac = $objsacdeb;
    
                } elseif ((count($pertanyaan_umum) < 8 || count($pertanyaan_khusus) < count($arr_khusus)) && ($sac['kode_sac'] > 2)) {
                    $flag_override = '1';
                    $result_prescreening = '1';
                    $cekHasil = '2';
                    $override .= " -Terdapat ketidaksesuaian pada SAC";
                    $objsacdeb = new stdClass;
                    $objsacdeb->nama_debitur = $nama;
                    $objsacdeb->result = $result_prescreening;
                    $objsacdeb->kode_warna = $sac['kode_sac'];
                    $objsacdeb->warna = $sac['warna'];
                    $objsacdeb->pertanyaan_umum = $arr_umum;
                    $objsacdeb->pertanyaan_khusus = $arr_khusus;
                    $objsacdeb->pertanyaan_spesifik = $arr_spesifik;
                    $objsacdeb->flag_override = "Y";
                    $objsac = $objsacdeb;
    
                } else {
                    $result_prescreening = '1';
                    $objsacdeb = new stdClass;
                    $objsacdeb->nama_debitur = $nama;
                    $objsacdeb->result = $result_prescreening;
                    $objsacdeb->kode_warna = $sac['kode_sac'];
                    $objsacdeb->warna = $sac['warna'];
                    $objsacdeb->pertanyaan_umum = $arr_umum;
                    $objsacdeb->pertanyaan_khusus = $arr_khusus;
                    $objsacdeb->pertanyaan_spesifik = $arr_spesifik;
                    $objsacdeb->flag_override = "N";
                    $objsac = $objsacdeb;
                }
                
                $objScreen = new stdClass;
                $objScreen->sac = $objsac;
                $objsacpers = new stdClass;
                $objsacpers->debitur = $objsac;
                array_push($screenSac, $objsacpers);
                
                $arrdata = array(
                    'upddate' => $dateActivity,
                    'override' => $objsacdeb->flag_override
                );
                $this->prakarsamediumRepo->updatePrakarsa($refno, $arrdata);
                
                $array_sac = array(
                    'nama' => $nama,
                    'refno' => $refno,
                    'nik' => $nik,
                    'branch' => $branch,
                    'userid_ao' => $pn,
                    'data' => json_encode($objsac),
                    'updated' => $dateActivity
                );
                
                $this->prakarsamediumRepo->updatePrescreenSac($array_sac);
                
                if ($flag_bkpm == 1) {
                    $objbkpmdeb = new stdClass;
                    $objbkpmdeb->nama_debitur = $nama;
                    $objbkpmdeb->result = $flag_bkpm;
                    $objbkpm = $objbkpmdeb;
                    
                } else{
                    $objbkpmdeb = new stdClass;
                    $objbkpmdeb->nama_debitur = $nama;
                    $objbkpmdeb->result = $flag_bkpm;
                    $tolak_bkpm = " -Gagal pada BKPM";
                    $cekHasil = '0';
                    $objbkpm = $objbkpmdeb;
                }
               
                $objbkpmpers = new stdClass;
                $objbkpmpers->debitur = $objbkpm;
                array_push($screenBkpm, $objbkpmpers);
    
                $array_bkpm = array(
                    'nama' => $nama,
                    'refno' => $refno,
                    'nik' => $nik,
                    'branch' => $branch,
                    'userid_ao' => $pn,
                    'data' => json_encode($objbkpm),
                    'updated' => $dateActivity
                );
                $this->prakarsamediumRepo->updatePrescreenBkpm($array_bkpm);
                
            if (!isset($request->person)) {
                throw new DataNotFoundException("Person yang akan diprescreening tidak ditemukan.");
            }
            if (!is_array($request->person)) {
                throw new InvalidRuleException("Format data person tidak sesuai.'");
            }
            $person = $request->person;
            $slik_selected = $request->slik_selected;
            
            $sikp = '';
            $screenSid = array();
            $screenScid = array();
            $screenDhn = array();
            $screenKemend = array();
            $screenHasil = "";
            $objsikp = new stdClass();
            $objsikp->debitur = null;
            
            foreach ($person as $id => $val) {
                $objScreen = new stdClass;
                if (!isset($val['company']) || !in_array($val['company'], ['0', '1'])) {
                    throw new InvalidRuleException("Prescreening gagal. Jenis debitur salah");
                }
                $company = trim($val['company']);
                $custtype = trim($val['custtype']);
    
                if (!isset($val['nik']) || trim($val['nik']) == '') {
                    throw new InvalidRuleException($company == '1' ? "npwp badan usaha kosong. " : "nik debitur kosong. ");
                }
                $nik = trim($val['nik']);
                if ($tp_produk == '47' || $tp_produk == '48') {
                    if (trim($val['company']) == '1') {
                        $sikp = $nik;
                    } else {
                        if ($sikp == '') {
                            if (isset($val->sikp_pic) && trim($val->sikp_pic) != '') {
                                $sikp = $nik;
                            }
                        }
                    }
                }
    
                if (!isset($val['nama']) || trim($val['nama']) == '') {
                    throw new ParameterException("Prescreening gagal. nama debitur kosong/salah");
                }
                $nama = trim($val['nama']);
    
                if ($company == '0') {
                    if (!isset($val['alias']) || trim($val['alias']) == '') {
                        throw new ParameterException("Prescreening gagal. nama alias debitur kosong/salah");
                    }
                    $alias = trim($val['alias']);
                    if (!isset($val['gender']) || trim($val['gender']) == '' || !in_array($val['gender'],['l','p'])) {
                        throw new ParameterException("Prescreening gagal. gender debitur kosong/salah");
                    }
                    $gender = trim($val['gender']);
                } else {
                    if ($val['gender'] == 'l'){
                        $val['gender'] = '1';
                    } elseif($val['gender'] == 'p'){
                        $val['gender'] = '2';
                    } else {
                        $val['gender'] = '0';
                    }
                    
                    $gender = $val['gender'];
                    $arrgender[$gender] = $val['gender'];

                    $alias = trim($val['nama']);
                }
                if (!isset($val['tgllahir']) || trim($val['tgllahir']) == '') {
                    throw new ParameterException("Prescreening gagal. tanggal lahir debitur kosong.");
                }

                if ($val['tgllahir'] != date('Y-m-d', strtotime($val['tgllahir']))) {
                    throw new ParameterException("Format tanggal lahir salah");
                }
                $tgllahir = $val['tgllahir'];

                if (!isset($val['tempatlahir']) || trim($val['tempatlahir']) == '') {
                    if ($company == '0') {
                        throw new InvalidRuleException("tempat lahir debitur kosong.");
                    }
                }
                $tempatlahir = trim($val['tempatlahir']);
                
                if (!isset($val['company']) || !in_array($val['company'], ['0','1'])) {
                    throw new InvalidRuleException("Prescreening gagal. Jenis debitur salah");
                }
                
                if (!in_array($val['custtype'], ['A', 'S', 'J', 'C', 'Y', 'N', 'T'])) { 
                    throw new InvalidRuleException("Custtype salah");
                }
                $custtype = $val['custtype'];
                //A = Peminjam, S = Pasangan Peminjam, J = Penjamin, C = Perusahaan, Y = Pemilik, Saham diatas 25%, N = Pemilik Saham dibawah 25%, T = Pengurus Tanpa Kepemilikan
    
                $cekpengajuandebitur = $this->prakarsamediumRepo->cekPengajuanPrescreeningSme($branch, $pn, $refno, $nik, $nama);
                
                $array_custtype_to_nik = array();
                $getPengajuanPrescreen = $this->prakarsamediumRepo->cekPengajuanPrescreeningSmeByRefno($refno);
               
                foreach($getPengajuanPrescreen as $res_pengajuan)
                {
                    
                    $array_custtype_to_nik[$res_pengajuan->nik] = $res_pengajuan->custtype;
                    
                }
                
                if($array_custtype_to_nik !== $custtype)
                {
                    $dataUpd2 = array(
                        'custtype' => $custtype 
                    );
                    $update_selected = $this->prakarsamediumRepo->updateCusttype($refno, $nik, $dataUpd2);
                    
                }
                
                if (count($cekpengajuandebitur) == 0 ) {
                    
                    $cekHasil = '0';
                    $flagawaldebitur = ($tp_produk == '12' ? '1' : '0');
                    $flagawaldebSIKP = ($val['company'] == '1' ? '0' : '1');
                    
                    if ($val['company'] == '0') {
                        if (isset($val['company'])) {
                            $flagawaldebSIKP = '0';
                        } else {
                            $flagawaldebSIKP = '2';
                        }
                    } else {
                        $flagawaldebSIKP = '0';
                    }

                    $objkemdeb = new stdClass;
                    $objkemdeb->success = false;
                    $objkemdeb->code = 200;
                    $objkemdeb->message = "No Data Found";
                    $objkemendagri = $objkemdeb;
                    $objkemenpers = new stdClass;
                    $objkemenpers->$nama = $objkemendagri;
                    array_push($screenKemend, $objkemendagri);
                    // $bypass_prescreening_slik = true;
                    $slik_cbasid = $flagawaldebitur == '1' ? "SME".date('ymd').random_int(10000, 99999) : NULL;
                    // $slik_cbasid = $bypass_prescreening_slik == FALSE ? "SME".date('ymd').random_int(10000, 99999) : NULL;
                    $arrdata = array(
                        'pernr' => $pn,
                        'branch' => $branch,
                        'tp_produk' => $tp_produk,
                        'refno' => $refno,
                        'bibr' => $bibr,
                        'custtype' => $custtype,
                        'nik' => $nik,
                        'nama' => $nama,
                        'alias' => $alias,
                        'gender' => $arrgender[$gender],
                        'tgl_lahir' => $tgllahir,
                        'tempat_lahir' => $tempatlahir,
                        'company' => $company,
                        'kemendagri' => $custtype == 'C' ? '1' : $flagawaldebitur,
                        'dhn' => $flagawaldebitur,
                        'sikp' => $flagawaldebSIKP,
                        'sicd' => $flagawaldebitur,
                        'ps' => '1',
                        'bkpm' => $flag_bkpm,
                        'slik' => $flagawaldebitur,
                        'add_slik' => $flagawaldebitur,
                        'slik_cbasid' => $slik_cbasid,
                        'onprocess' => '0',
                        'done' => $flagawaldebitur,
                        'insert' => Carbon::now()->format('Y-m-d H:i:s')
                    );
                    
                    $this->prakarsamediumRepo->insertPengajuanPrescreeningSme($refno, $arrdata);
                   
                    if ($tp_produk == '49' || $tp_produk == '50' ) {
                        $msgcomplete = "DHN debitur, KEMENDAGRI, SLIK, SICD, SIKP.";
                    } else {
                        $msgcomplete = "DHN debitur, KEMENDAGRI, SLIK, SICD.";
                    }
    
                    $objdhndeb = new stdClass;
                    $objdhndeb->result = null;
                    $objdhn = $objdhndeb;
                    
                    $objdhnpers = new stdClass;
                    $objdhnpers->debitur = $objdhn;
                    array_push($screenDhn, $objdhnpers);
    
                    $objsicddeb = new stdClass;
                    $objsicddeb->status = null;
                    $objsicddeb->acctno = null;
                    $objsicddeb->cbal = null;
                    $objsicddeb->bikole = null;
                    $objsicddeb->result = null;
                    $objsicddeb->cif = null;
                    $objsicddeb->nama_debitur = null;
                    $objsicddeb->tgl_lahir = null;
                    $objsicddeb->alamat = null;
                    $objsicddeb->no_identitas = null;
                    $objsicd = array($objsicddeb);
                    $objScreen->sicd = $objsicd;
                    $objsicdpers = new stdClass;
                    $objsicdpers->debitur = $objsicd;
                    array_push($screenScid, $objsicdpers);
    
                    $objsiddeb = new stdClass;
                    $objsiddeb->result = null;
                    $objsiddeb->din = null;
                    $objsiddeb->nama_debitur = null;
                    $objsiddeb->filename = null;
                    $objsiddeb->no_identitas = null;
                    $objsiddeb->tgl_lahir = null;
                    $objsiddeb->alamat_debitur = null;
                    $objsiddeb->dati2_debitur = null;
                    $objsiddeb->kode_pos = null;
                    $objsiddeb->id = null;
                    $objsiddeb->detail = null;
                    $objsid = array($objsiddeb);
                    $objsid['attachlink'] = env('BRISPOT_MCS_URL') . "docs/SID1/";
                    $objScreen->sidbi = $objsid;
                    $objsidpers = new stdClass;
                    $objsidpers->debitur = $objsid;
                    array_push($screenSid, $objsidpers);
                    
                } else {
                    
                    if ($cekpengajuandebitur[0]->dhn == '1') {
                        
                        $datapre = $this->prakarsamediumRepo->getPrescreenDhn($refno, $nama, $tgllahir, $branch, $pn);
                        
                        if (count($datapre) > 0) {
                            $arrres = array();
                            foreach ($datapre as $rowres) {
                                $datares = json_decode($rowres->data);
                                
                                if ($datares->result == "1"){
                                    $tolak_dhn = " -Gagal pada DHN";
                                    $cekHasil = '0';
                                }
                                array_push($arrres, $datares);
                            }
                            $objdhn = $datares;
                            
                        } else {
                            
                            $cekHasil = '0';
                            $objdhndeb = new stdClass;
                            $objdhndeb->result = null;
                            $objdhn = $objdhndeb;
                            $msgcomplete .= "DHN debitur, ";
                            $objScreen->dhn = $objdhn;
                            $msgcomplete = "DHN, ";
                            
                        }
                        $objScreen->dhn = $objdhn;
                       
                    } else {
                        $cekHasil = '0';
                        $objdhndeb = new stdClass;
                        $objdhndeb->result = null;
                        $objdhn = $objdhndeb;
                        $msgcomplete .= "DHN debitur, ";
                        $objScreen->dhn = $objdhn;
                        $msgcomplete = "DHN, ";
                    }
                    
                    $objdhnpers = new stdClass;
                    $objdhnpers->debitur = $objdhn;
                    array_push($screenDhn, $objdhnpers);

                    $link_file = env('BRISPOT_MONOLITHIC_URL');
                    $new_link_file = rtrim($link_file, '/service');
                   
                    if ($cekpengajuandebitur[0]->slik == '1') {
                        $getPengajuanPrescreen = $this->prakarsamediumRepo->cekPengajuanPrescreeningSmeByRefno($refno);
                        
                        $array_custtype = [];
                        $array_nama_debitur = [];
                        foreach ($getPengajuanPrescreen as $data => $value ){
                            array_push($array_custtype, $value->custtype);
                            $array_nama_debitur[$value->nik] = $value->nama;
                           
                        }
                        
                        $nik_slik = [];
                        $id_host_slik = [];
                        $request_selected = [];
                        foreach ($slik_selected as $id_slik => $val2 ){
                           
                            $dataUpd = array(
                                'selected' => $val2['selected']
                            );
                            
                            $update_selected = $this->prakarsamediumRepo->updateSelectedSlik($refno, $val2['nik'], $val2['id_host'], $dataUpd);
                        }
                        
                        $datapre = $this->prakarsamediumRepo->getPrescreenSlikSmeNoAlias($refno, $tgllahir, $branch, $pn, $nik);
                        
                        if (count($datapre) > 0) {
                            $arrres = array();
                            $i = 0;
                            
                            foreach ($datapre as $rowres) {
                                
                                $datares = json_decode($rowres->result);
                                
                                if(isset($array_nama_debitur[$rowres->nik]))
                                {
                                    if($datares->nama_debitur == null)
                                    {
                                        $datares->nama_debitur = $array_nama_debitur[$rowres->nik];
                                    }
                                }
                                
                                if (!empty(array_intersect($array_custtype, ['A','S','J','C','Y']))){
                                    
                                    if ($datapre[0]->selected == '1' && $datares->result == '1.0'){
                                        $cekHasil = '0';
                                        $tolak_slik = " -Gagal pada SLIK";
                                    }
                                }
                               
                                if (isset($datares->Detail)) {
                                    
                                    foreach ($datares->Detail as $vals) {
                                        
                                        $i++;
                                        $datares = $vals;
                                        $datares->id = $i;
                                        $datares->selected = $rowres->selected;
                                        $tahun = substr(substr($vals->filename, -18), 0, 4);
                                        $bulan = substr(substr($vals->filename, -14), 0, 2);
                                        $hari = substr(substr($datares->filename, -12), 0, 2);
                                        $newfilename1 = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                        if (file_exists($new_link_file . "/docs/SID1/" . $newfilename1)) {
                                            $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                        } else {
                                            $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                        }
                                        array_push($arrres, $datares);
                                    }
                                    
                                } else {
                                    $datares->id = $rowres->id;
                                    $datares->selected = $rowres->selected;
                                    $tahun = substr(substr((string)$datares->filename, -18), 0, 4);
                                    $bulan = substr(substr((string)$datares->filename, -14), 0, 2);
                                    $hari = substr(substr((string)$datares->filename, -12), 0, 2);
                                    $newfilename1 = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                    if (file_exists($new_link_file . "/docs/SID1/" . $newfilename1)) {
                                        $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                    } else {
                                        $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                    }
                                    $datares->filename = $newfilename;
                                    array_push($arrres, $datares);
                                }
                                
                            }
                            $objsid = $arrres;
                           
                        } else {
                            $cekHasil = '0';
                            $msgcomplete .= 'SLIK debitur, ';
                            $objsiddeb = new stdClass;
                            $objsiddeb->result = null;
                            $objsiddeb->din = null;
                            $objsiddeb->nama_debitur = null;
                            $objsiddeb->filename = null;
                            $objsiddeb->no_identitas = null;
                            $objsiddeb->tgl_lahir = null;
                            $objsiddeb->alamat_debitur = null;
                            $objsiddeb->dati2_debitur = null;
                            $objsiddeb->kode_pos = null;
                            $objsiddeb->id = null;
                            $objsiddeb->detail = null;
                            $objsiddeb->selected = '0';
                            $objsid = array($objsiddeb);
                            $msgcomplete = "SLIK, ";
                            
                        }
                        $objScreen->sid = $objsid;
                        
                    } else {
                        $cekHasil = '0';
                        $objsiddeb = new stdClass;
                        $objsiddeb->result = null;
                        $objsiddeb->din = null;
                        $objsiddeb->nama = $nama;
                        $objsiddeb->nama_debitur = null;
                        $objsiddeb->filename = null;
                        $objsiddeb->no_identitas = null;
                        $objsiddeb->tgl_lahir = null;
                        $objsiddeb->alamat_debitur = null;
                        $objsiddeb->dati2_debitur = null;
                        $objsiddeb->kode_pos = null;
                        $objsiddeb->id = null;
                        $objsiddeb->detail = null;
                        $objsiddeb->selected = '0';
                        $objsid = array($objsiddeb);
                        $msgcomplete .= "SLIK debitur, ";
                        $objScreen->sid = $objsid;
                        $msgcomplete = "SLIK, ";
                    }
                    
                    $objsidpers = new stdClass;
                    
                    $objsidpers->attachlink = (count($datapre) > 0) ? $new_link_file . "/docs/SID1/" : null;
                    $objsidpers->debitur = $objsid;
                    array_push($screenSid, $objsidpers);
                    
                    if ($cekpengajuandebitur[0]->kemendagri == '1') {
                        $datapre = $this->prakarsamediumRepo->getPrescreenKemendagri($refno,$nik);
                        
                        if (count($datapre) > 0) {
                            $arrres = array();
                            $i = 0;
                            foreach ($datapre as $rowres) {
                                $datares = json_decode($rowres->data);
                                array_push($arrres, $datares);
                            }
                            $objkemendagri = $datares;
                        } else if($custtype == 'C'){
                            $objkemdeb = new stdClass;
                            $objkemdeb->nik = $nik;
                            $objkemdeb->namaLengkap = $nama;
                            $objkemendagri = $objkemdeb;
                            $objScreen->kemendagri = $objkemendagri;
                        }
                        else {
                            $cekHasil = '0';
                            $objkemdeb = new stdClass;
                            $objkemdeb->success = false;
                            $objkemdeb->code = 200;
                            $objkemdeb->message = "No Data Found";
                            $objkemendagri = $objkemdeb;
                            $msgcomplete .= "kemendagri debitur, ";
                            $objScreen->kemendagri = $objkemendagri;
                            $msgcomplete = "KEMENDAGRI, ";
                        }
                        $objScreen->kemendagri = $objkemendagri;
                    } else {
                        $cekHasil = '0';
                        $objkemdeb = new stdClass;
                        $objkemdeb->success = false;
                        $objkemdeb->code = 200;
                        $objkemdeb->message = "No Data Found";
                        $objkemendagri = $objkemdeb;
                        $msgcomplete .= "kemendagri debitur, ";
                        $objScreen->kemendagri = $objkemendagri;
                        $msgcomplete = "KEMENDAGRI, ";
                    }
                    $objkemenpers = new stdClass;
                    $objkemenpers->debitur = $objkemendagri;
                    array_push($screenKemend, $objkemenpers);
                    
                    if ($cekpengajuandebitur[0]->sicd == '1') {
                        
                        $getPengajuanPrescreen = $this->prakarsamediumRepo->cekPengajuanPrescreeningSmeByRefno($refno);
                        
                        $array_nik = [];
                        $array_custtype = [];
                        $array_nama_sicd = [];
                        foreach ($getPengajuanPrescreen as $data => $value ){
                            array_push($array_nik, $value->nik);
                            array_push($array_nama_sicd, $value->nama);
                            array_push($array_custtype, $value->custtype);
                        }
                        
                        $datapre = $this->prakarsamediumRepo->getPrescreenSicd($refno, $nama, $tgllahir, $branch, $pn);
                        
                        if (count($datapre) > 0) {
                            $arrres = array();
                            
                            foreach ($datapre as $rowres) {
                                $datares = json_decode($rowres->data);
                                $datares->id = $rowres->id;
                                $datares->is_ph = '0';
                                // $datares->tgl_ph = date('Y-m-d', strtotime("-730 day", strtotime(date("Y-m-d"))));
                                $datares->tgl_ph = '2021-01-02';
                                $no_identitas = $datares->no_identitas;
                                
                                if ((in_array($no_identitas, $array_nik) || $no_identitas == null) && (in_array($rowres->nama, $array_nama_sicd))) {
                                    
                                    if (!empty(array_intersect($array_custtype,array('A','C')))){
                                        if (($datares->bikole > 2 || $datares->result == '1') || ($datares->is_ph == '1' && $datares->tgl_ph > date('Y-m-d', strtotime("-730 day", strtotime(date("Y-m-d")))))){
                                            $cekHasil = '0';
                                            $tolak_sicd = " -Gagal pada SICD";
                                        }
                                    }
                                    
                                    array_push($arrres, $datares);
                                }
                                
                                $objsicd = $arrres;
                                
                            }
                            
                        } else {
                            
                            $objsicddeb = new stdClass;
                            $objsicddeb->pemilik = null;
                            $objsicddeb->status = null;
                            $objsicddeb->acctno = null;
                            $objsicddeb->cbal = null;
                            $objsicddeb->bikole = null;
                            $objsicddeb->result = null;
                            $objsicddeb->cif = null;
                            $objsicddeb->nama_debitur = null;
                            $objsicddeb->tgl_lahir = null;
                            $objsicddeb->alamat = null;
                            $objsicddeb->no_identitas = null;
                            $objsicddeb->id = null;
                            $objsicd = array($objsicddeb);
                        }
                        $objScreen->sicd = $objsicd;
                    } else {
                        $objsicddeb = new stdClass;
                        $objsicddeb->pemilik = null;
                        $objsicddeb->status = null;
                        $objsicddeb->acctno = null;
                        $objsicddeb->cbal = null;
                        $objsicddeb->bikole = null;
                        $objsicddeb->result = null;
                        $objsicddeb->cif = null;
                        $objsicddeb->nama_debitur = null;
                        $objsicddeb->tgl_lahir = null;
                        $objsicddeb->alamat = null;
                        $objsicddeb->no_identitas = null;
                        $objsicddeb->id = null;
                        $objsicd = array($objsicddeb);
                        $msgcomplete .= "SICD debitur, ";
                        $objScreen->sicd = $objsicd;
                        $msgcomplete = "SICD, ";
                    }
                    
                    $objsicdpers = new stdClass;
                    $objsicdpers->debitur = $objsicd;
                    array_push($screenScid, $objsicdpers);

                    if ($tp_produk == '47' || $tp_produk == '48') {
                        if ($cekpengajuandebitur[0]->sikp == '1' || trim($val->sikp) == '1') {
                            $datapre = $this->prakarsamediumRepo->getPrescreenSikp($refno, $nik, $branch, $pn);
                            if (count($datapre) > 0) {
                                $objsikp->debitur = $datapre->data;
                            }
                        }
                    }
                    
                }
                
            }

            if ($flag_override == '1'){
                $param_override = new stdClass;
                $param_override->refno = $request->refno;
                $param_override->id_override = '1';
                $param_override->ket_override = 'Prescreening : '.$override;
                $param_override->flag_override = '1';

                $request_override = new stdClass();
                $request_override->requestMethod = "insertOverrideSME";
                $request_override->requestUser = $request->pn;
                $request_override->requestData = $param_override;
                $response_override = $mono_client->call($request_override)->getObjectResponse();
                if (empty($response_override) || (isset($response_override->responseCode) && $response_override->responseCode <> "00")) {
                    throw new InvalidRuleException(isset($response_override->responseDesc) ? $response_override->responseDesc : "Gagal insert data override");
                }
            } elseif ($flag_override == '0'){
                $param_override = new stdClass;
                $param_override->refno = $request->refno;
                $param_override->id_override = '1';
                $param_override->ket_override = 'Prescreening : '.$override;
                $param_override->flag_override = '0';

                $request_override = new stdClass();
                $request_override->requestMethod = "insertOverrideSME";
                $request_override->requestUser = $request->pn;
                $request_override->requestData = $param_override;
                $response_override = $mono_client->call($request_override)->getObjectResponse();
                if (empty($response_override) || (isset($response_override->responseCode) && $response_override->responseCode <> "00")) {
                    throw new InvalidRuleException(isset($response_override->responseDesc) ? $response_override->responseDesc : "Gagal insert data override");
                }
            }
            
            if ($cekHasil == '0') {
                $keterangan .= $keterangan_tolak .''. $tolak_ps .$tolak_bkpm .$tolak_dhn .$tolak_slik .$tolak_sicd .$tolak_kemendagri;
                $data_ket = !empty($getciflas->keterangan_lainnya) ? json_decode($getciflas->keterangan_lainnya) : [];
                $newData = [];
                $newData['tipe']         = 'tolak_prakarsa';
                $newData['keterangan']   = $tolak_ps .$tolak_bkpm .$tolak_dhn .$tolak_slik .$tolak_sicd .$tolak_kemendagri;
                
                array_push($data_ket, $newData); 

                $dataUpdateKet = array('keterangan_lainnya' => json_encode($data_ket));
                $update_prakarsa = $this->prakarsamediumRepo->updatePrakarsa($request->refno, $dataUpdateKet);

            } elseif ($cekHasil == '2'){
                $keterangan = $keterangan_override .''. $override;
            } else{
                $keterangan = $keterangan_lolos ;
            }
            
            if ($msgpre == true) {
                $warningmsg = "Pejabat Kredit Lini diwajibkan melakukan pemeriksaan download hasil SLIK pdf sebelum melanjutkan proses prakarsa dan putusan kredit.";
            }
    
            $this->output->responseCode = '00';
            $this->output->responseDesc = 'Prescreening selesai.' . '\n' . $warningmsg;
            $resp = new stdClass;
            $resp->hasil_prescreening = $cekHasil;
            $resp->keterangan = $keterangan;
            $resp->sid = $screenSid;
            $resp->sicd = $screenScid;
            $resp->dhn = $screenDhn;
            $resp->kemendagri = $screenKemend;
            $resp->pasar_sasaran = $screenPs;
            $resp->sac = $screenSac;
            $resp->bkpm = $screenBkpm;
            if ($tp_produk == '47' || $tp_produk == '48') {
                $resp->sikp = $objsikp;
            }
            $this->output->responseData = $resp;

            if (trim($msgcomplete) == '') {
                $this->output->responseCode = '00';
                $this->output->responseDesc = 'Prescreening selesai.'.$warningmsg;
                // return response()->json($this->output);
            } else {
                $this->output->responseCode = '04';
                $this->output->responseDesc = 'Prescreening ' . substr($msgcomplete, 0, -2) . ' sedang diproses, silakan cek setiap 10 menit.';
                // return response()->json($this->output);
            }
            
        } else {
            $responseData = new stdClass;
            $this->output->responseCode = '00';
            $this->output->responseDesc = 'Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.';
            $this->output->responseData = $responseData;
            // return response()->json($this->output);
        }

        return response()->json($this->output);
    }

    public function insertUpdateIdentitasUsahaSME(Request $request, RestClient $client) {

        if (!isset($request->pn)) {
            throw new ParameterException("Parameter PN tidak valid");
        }
        $pn = $request->pn;

        if (!isset($request->branch)) {
            throw new ParameterException("Parameter branch tidak valid");
        }
        $branch = $request->branch;
        
        if (!isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        $refno = $request->refno;
        $jenis_surat = "";
        $expired_perijinan_lain = "";
        $next_perijinan_lain = "";
        $npwp = isset($request->npwp) ? $request->npwp : null;

        // pengecekan dan validasi jumlah NPWP (menyesuaikan dengan format NPWP 16 digit)
        if (!empty($npwp) && !in_array(strlen($npwp), explode(",", env('DIGIT_NPWP_VALID')))) {
            throw new InvalidRuleException('Periksa kembali inputan NPWP anda');
        }

        // pengecekan surat
        if (isset($request->jenis_surat)) {
            foreach ($request->jenis_surat as $val) {
                if ($jenis_surat == "") {
                    $jenis_surat = $val;
                } else {
                    $jenis_surat = $jenis_surat . "||" . $val;
                }

                if (!in_array($val, ['SKU','TDP','SIUP','SITU','SIUJK','APE_APES','PERIJINAN_LAINNYA','PERIJINAN_LAINNYA_1','PERIJINAN_LAINNYA_2'])) {
                    throw new InvalidRuleException("Isian jenis surat salah. Yang bisa dipilih hanya SKU,TDP,SIUP,SITU,SIUJK,APE_APES,PERIJINAN_LAINNYA,PERIJINAN_LAINNYA_1,PERIJINAN_LAINNYA_2");
                } else if ($val == 'SKU' && trim($request->surat_keterangan) == '') {
                    throw new ParameterException("pilihan SKU, field surat keterangan harus diisi.");
                } else if (isset($val) && $val == "TDP" && isset($cekdata) && $cekdata == true) {
                    if (isset($request->tdp) && !empty($request->tdp)) {
                        if ($request->expired_tdp != date('Y-m-d', strtotime($request->expired_tdp))) {
                            throw new ParameterException("Format tanggal TDP salah");
                        }
                        if ($request->next_tdp != date('Y-m-d', strtotime($request->next_tdp))) {
                            throw new ParameterException("Format tanggal next TDP salah");
                        }                        
                    } 
                } else if (isset($val) && $val == "SIUP" && isset($cekdata) && $cekdata == true) {
                    if (isset($request->siup) && !empty($request->siup)) {
                        if ($request->expired_siup != date('Y-m-d', strtotime($request->expired_siup))) {
                            throw new ParameterException("Format tanggal expired SIUP salah");
                        }
                        if ($request->next_siup != date('Y-m-d', strtotime($request->next_siup))) {
                            throw new ParameterException("Format tanggal next SIUP salah");
                        }
                    } 
                } else if (isset($val) && $val == "SITU" && $cekdata == true) {
                    if (!isset($request->situ) || empty($request->situ)) {
                        throw new ParameterException("Nomor SITU tidak boleh kosong");
                    }
                    if ($request->expired_situ != date('Y-m-d', strtotime($request->expired_situ))) {
                        throw new ParameterException("Format tanggal expired SITU salah");
                    }
                    if ($request->next_situ != date('Y-m-d', strtotime($request->next_situ))) {
                        throw new ParameterException("Format tanggal next SITU salah");
                    }
                   
                } else if (isset($val) && $val == "SIUJK" && $cekdata == true) {
                    if (!isset($request->siujk) || empty($request->siujk)) {
                        throw new ParamaterException("Nomor SIUJK tidak boleh kosong");
                    }
                    if ($request->expired_siujk != date('Y-m-d', strtotime($request->expired_siujk))) {
                        throw new ParameterException("Format tanggal expired SIUJK salah");
                    }
                    if ($request->expired_siujk != date('Y-m-d', strtotime($request->next_siujk))) {
                        throw new ParameterException("Format tanggal next SIUJK salah");
                    }
                    
                } else if (isset($val) && $val == "APE_APES" && $cekdata == true) {
                    if (!isset($request->ape_apes) || empty($request->ape_apes)) {
                        throw new ParameterException("Nomor APE APES tidak boleh kosong");
                    }
                    if ($request->expired_ape_apes != date('Y-m-d', strtotime($request->expired_ape_apes))) {
                        throw new ParameterException("Format tanggal expired APE APES salah");
                    }
                    if ($request->next_ape_apes != date('Y-m-d', strtotime($request->next_ape_apes))) {
                        throw new ParameterException("Format tanggal APE APES berikutnya salah");
                    }
                    
                } else if (isset($val) && $val == "PERIJINAN_LAIN" && $cekdata == true) {
                    if (!isset($request->perijinan_lain) || empty($request->perijinan_lain)) {
                        throw new ParameterException("Nomor PERIJINAN_LAINNYA tidak boleh kosong.");
                    }
                    if ($request->expired_perijinan_lain != date('Y-m-d', strtotime($request->expired_perijinan_lain))) {
                        throw new ParameterException("Format tanggal expired Perijinan Lain salah");
                    }
                    if ($request->next_perijinan_lain != date('Y-m-d', strtotime($request->next_perijinan_lain))) {
                        throw new ParameterException("Format tanggal next Perijinan Lain berikutnya salah");
                    }
                    
                } else if (isset($val) && $val == "PERIJINAN_LAINNYA_1" && $cekdata == true) {
                    if (!isset($request->perijinan_lain_1) || empty($request->perijinan_lain_1)) {
                        throw new ParameterException("Nomor PERIJINAN_LAINNYA_1 tidak boleh kosong.");
                    }
                    if ($request->expired_perijinan_lain_1 != date('Y-m-d', strtotime($request->expired_perijinan_lain_1))) {
                        throw new ParameterException("Format tanggal expired Perijinan Lain 1 salah");
                    }
                    if ($request->next_perijinan_lain_1 != date('Y-m-d', strtotime($request->next_perijinan_lain_1))) {
                        throw new ParameterException("Format tanggal Perijinan Lain berikutnya 1 salah");
                    }
                
                } else if (isset($val) && $val == "PERIJINAN_LAINNYA_2" && $cekdata == true) {
                    if (!isset($request->perijinan_lain_2) || empty($request->perijinan_lain_2)) {
                        throw new ParameterException("Nomor PERIJINAN_LAINNYA_2 tidak boleh kosong. ");
                    }
                    if ($request->expired_perijinan_lain_2 != date('Y-m-d', strtotime($request->expired_perijinan_lain_2))) {
                        throw new ParameterException("Format tanggal expired Perijinan Lain 2 salah");
                    }
                    if ($request->next_perijinan_lain_2 != date('Y-m-d', strtotime($request->next_perijinan_lain_2))) {
                        throw new ParameterException("Format tanggal Perijinan Lain berikutnya 2 salah");
                    }
                    
                }
            }
            if (isset($request->desc)) {
                if ($jenis_surat == "") {
                    $jenis_surat = "PERIJINAN_LAIN";
                } else {
                    $jenis_surat = $jenis_surat . "||PERIJINAN_LAIN";
                }
                foreach ($request->desc as $key => $val_desc) {
                    $desc = json_decode($val_desc);
                    
                    $arrnext_lain = explode('-', $desc->issued_date);
                    $next_lain = $arrnext_lain[2] . $arrnext_lain[1] . $arrnext_lain[0];

                    $arrexpired_lain = explode('-', $desc->issued_date);
                    $expired_lain = $arrexpired_lain[2] . $arrexpired_lain[1] . $arrexpired_lain[0];

                    if ($key == 0) {
                        $perijinan_lain = $desc->nomor_surat;
                        $expired_perijinan_lain = $next_lain;
                        $next_perijinan_lain = $expired_lain;
                    } else {
                        $perijinan_lain = $perijinan_lain . "||" . $desc->nomor_surat;
                        $expired_perijinan_lain = $expired_perijinan_lain . "||" . $next_lain;
                        $next_perijinan_lain = $next_perijinan_lain . "||" . $expired_lain;
                    }
                }
            }
        }
        
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);
        
        if (empty($get_prakarsa)) {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        if ($get_prakarsa->status != '1') {
            throw new InvalidRuleException("Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.");
        }

        $ciflas = $get_prakarsa->cif_las;
        $clientResponse = $client->call(
            [

                "jenis_surat" => $request->jenis_surat,
                "fid_cif_las" => $ciflas,
                "surat_keterangan" => (isset($request->surat_keterangan) ? trim($request->surat_keterangan) : ''),
                "tdp" => (isset($request->tdp) ? trim($request->tdp) : ''),
                "expired_tdp" => (isset($expired_tdp) ? trim($expired_tdp) : ''),
                "next_tdp" => (isset($next_tdp) ? trim($next_tdp) : ''),
                "siup" => (isset($request->siup) ? trim($request->siup) : ''),
                "expired_siup" => (isset($expired_siup) ? trim($expired_siup) : ''),
                "next_siup" => (isset($next_siup) ? trim($next_siup) : ''),
                "situ" => (isset($request->situ) ? trim($request->situ) : ''),
                "expired_situ" => (isset($expired_situ) ? trim($expired_situ) : ''),
                "next_situ" => (isset($next_situ) ? trim($next_situ) : ''),
                "siujk" => (isset($request->siujk) ? trim($request->siujk) : ''),
                "expired_siujk" => (isset($expired_siujk) ? trim($expired_siujk) : ''),
                "next_siujk" => (isset($next_siujk) ? trim($next_siujk) : ''),
                "ape_apes" => (isset($request->ape_apes) ? trim($request->ape_apes) : ''),
                "expired_ape_apes" => (isset($expired_ape_apes) ? trim($expired_ape_apes) : ''),
                "next_ape_apes" => (isset($next_ape_apes) ? trim($next_ape_apes) : ''),
                "perijinan_lain" => (isset($perijinan_lain) ? trim($perijinan_lain) : ''),
                "expired_perijinan_lain" => (isset($expired_perijinan_lain) ? trim($expired_perijinan_lain) : ''),
                "next_perijinan_lain" => (isset($next_perijinan_lain) ? trim($next_perijinan_lain) : ''),
                "npwp" => (isset($request->npwp) ? trim($request->npwp) : ''),
                "rating_perusahaan" => "",
                "lembaga_pemeringkat" => "",
                "tanggal_pemeringkatan" => "",
                "group_debitur" => "",
                "go_public" => "",
                "jml_tenaker" => (isset($request->jml_tenaker) ? trim($request->jml_tenaker) : ''),
                "perijinan_lain_1" => (isset($request->perijinan_lain_1) ? trim($request->perijinan_lain_1) : ''),
                "expired_perijinan_lain_1" => (isset($expired_perijinan_lain_1) ? trim($expired_perijinan_lain_1) : ''),
                "next_perijinan_lain_1" => (isset($next_perijinan_lain_1) ? trim($next_perijinan_lain_1) : ''),
                "perijinan_lain_2" => (isset($request->perijinan_lain_2) ? trim($request->perijinan_lain_2) : ''),
                "expired_perijinan_lain_2" => (isset($expired_perijinan_lain_2) ? trim($expired_perijinan_lain_2) : ''),
                "next_perijinan_lain_2" => (isset($next_perijinan_lain_2) ? trim($next_perijinan_lain_2) : ''),
                "latitude" => $request->latitude,
                "longitude" => $request->longitude,
                "desc_ll" => (isset($request->desc) ? $request->desc : ''),
                "expired_tdp" => (isset($request->expired_tdp) ? trim($request->expired_tdp) : ''),
                "next_tdp" => (isset($request->next_tdp) ? trim($request->next_tdp) : ''),
                "expired_siup" => (isset($request->expired_siup) ? trim($request->expired_siup) : ''),
                "next_siup" => (isset($request->next_siup) ? trim($request->next_siup) : ''),
                "expired_situ" => (isset($request->expired_situ) ? trim($request->expired_situ) : ''),
                "next_situ" => (isset($request->next_situ) ? trim($request->next_situ) : ''),
                "expired_siujk" => (isset($request->expired_siujk) ? trim($request->expired_siujk) : ''),
                "next_siujk" => (isset($request->next_siujk) ? trim($request->next_siujk) : ''),
                "expired_ape_apes" => (isset($request->expired_ape_apes) ? trim($request->expired_ape_apes) : ''),
                "next_ape_apes" => (isset($request->next_ape_apes) ? trim($request->next_ape_apes) : ''),
                "expired_perijinan_lain" => (isset($request->expired_perijinan_lain) ? trim($request->expired_perijinan_lain) : ''),
                "next_perijinan_lain" => (isset($request->next_perijinan_lain) ? trim($request->next_perijinan_lain) : ''),
                "expired_perijinan_lain_1" => (isset($request->expired_perijinan_lain_1) ? trim($request->expired_perijinan_lain_1) : ''),
                "next_perijinan_lain_1" => (isset($request->next_perijinan_lain_1) ? trim($request->next_perijinan_lain_1) : ''),
                "expired_perijinan_lain_2" => (isset($request->expired_perijinan_lain_2) ? trim($request->expired_perijinan_lain_2) : ''),
                "next_perijinan_lain_2" => (isset($request->next_perijinan_lain_2) ? trim($request->next_perijinan_lain_2) : ''),
                "desc" => $request->desc
                
            ], env('BRISPOT_EKSTERNAL_URL'), '/las/insertUpdateIdentitasUsahaSME');
           
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode != "00") {
            throw new DataNotFoundException($clientResponse->responseDesc);
        }

        $dataTempatUsaha = new stdClass;
        $dataTempatUsaha->jenis_surat = $request->jenis_surat;
        $dataTempatUsaha->fid_cif_las = $ciflas;
        $dataTempatUsaha->surat_keterangan = (isset($request->surat_keterangan) ? trim($request->surat_keterangan) : '');
        $dataTempatUsaha->tdp = (isset($request->tdp) ? trim($request->tdp) : '');
        $dataTempatUsaha->expired_tdp = (isset($expired_tdp) ? trim($expired_tdp) : '');
        $dataTempatUsaha->next_tdp = (isset($next_tdp) ? trim($next_tdp) : '');
        $dataTempatUsaha->siup = (isset($request->siup) ? trim($request->siup) : '');
        $dataTempatUsaha->expired_siup = (isset($expired_siup) ? trim($expired_siup) : '');
        $dataTempatUsaha->next_siup = (isset($next_siup) ? trim($next_siup) : '');
        $dataTempatUsaha->situ = (isset($request->situ) ? trim($request->situ) : '');
        $dataTempatUsaha->expired_situ = (isset($expired_situ) ? trim($expired_situ) : '');
        $dataTempatUsaha->next_situ = (isset($next_situ) ? trim($next_situ) : '');
        $dataTempatUsaha->siujk = (isset($request->siujk) ? trim($request->siujk) : '');
        $dataTempatUsaha->expired_siujk = (isset($expired_siujk) ? trim($expired_siujk) : '');
        $dataTempatUsaha->next_siujk = (isset($next_siujk) ? trim($next_siujk) : '');
        $dataTempatUsaha->ape_apes = (isset($request->ape_apes) ? trim($request->ape_apes) : '');
        $dataTempatUsaha->expired_ape_apes = (isset($expired_ape_apes) ? trim($expired_ape_apes) : '');
        $dataTempatUsaha->next_ape_apes = (isset($next_ape_apes) ? trim($next_ape_apes) : '');
        $dataTempatUsaha->perijinan_lain = (isset($perijinan_lain) ? trim($perijinan_lain) : '');
        $dataTempatUsaha->expired_perijinan_lain = (isset($expired_perijinan_lain) ? trim($expired_perijinan_lain) : '');
        $dataTempatUsaha->next_perijinan_lain = (isset($next_perijinan_lain) ? trim($next_perijinan_lain) : '');
        $dataTempatUsaha->npwp = (isset($request->npwp) ? trim($request->npwp) : '');
        $dataTempatUsaha->rating_perusahaan = $request->rating_perusahaan;
        $dataTempatUsaha->lembaga_pemeringkat = $request->lembaga_pemeringkat;
        $dataTempatUsaha->tanggal_pemeringkatan = $request->tanggal_pemeringkatan;
        $dataTempatUsaha->group_debitur = $request->group_debitur;
        $dataTempatUsaha->go_public = $request->go_public;
        $dataTempatUsaha->jml_tenaker = (isset($request->jml_tenaker) ? trim($request->jml_tenaker) : '');
        $dataTempatUsaha->perijinan_lain_1 = (isset($request->perijinan_lain_1) ? trim($request->perijinan_lain_1) : '');
        $dataTempatUsaha->expired_perijinan_lain_1 = (isset($expired_perijinan_lain_1) ? trim($expired_perijinan_lain_1) : '');
        $dataTempatUsaha->next_perijinan_lain_1 = (isset($next_perijinan_lain_1) ? trim($next_perijinan_lain_1) : '');
        $dataTempatUsaha->perijinan_lain_2 = (isset($request->perijinan_lain_2) ? trim($request->perijinan_lain_2) : '');
        $dataTempatUsaha->expired_perijinan_lain_2 = (isset($expired_perijinan_lain_2) ? trim($expired_perijinan_lain_2) : '');
        $dataTempatUsaha->next_perijinan_lain_2 = (isset($next_perijinan_lain_2) ? trim($next_perijinan_lain_2) : '');
        $dataTempatUsaha->latitude = $request->latitude;
        $dataTempatUsaha->longitude = $request->longitude;
        $dataTempatUsaha->desc_ll = (isset($request->desc) ? $request->desc : '');
        $dataTempatUsaha->expired_tdp = (isset($request->expired_tdp) ? trim($request->expired_tdp) : '');
        $dataTempatUsaha->next_tdp = (isset($request->next_tdp) ? trim($request->next_tdp) : '');
        $dataTempatUsaha->expired_siup = (isset($request->expired_siup) ? trim($request->expired_siup) : '');
        $dataTempatUsaha->next_siup = (isset($request->next_siup) ? trim($request->next_siup) : '');
        $dataTempatUsaha->expired_situ = (isset($request->expired_situ) ? trim($request->expired_situ) : '');
        $dataTempatUsaha->next_situ = (isset($request->next_situ) ? trim($request->next_situ) : '');
        $dataTempatUsaha->expired_siujk = (isset($request->expired_siujk) ? trim($request->expired_siujk) : '');
        $dataTempatUsaha->next_siujk = (isset($request->next_siujk) ? trim($request->next_siujk) : '');
        $dataTempatUsaha->expired_ape_apes = (isset($request->expired_ape_apes) ? trim($request->expired_ape_apes) : '');
        $dataTempatUsaha->next_ape_apes = (isset($request->next_ape_apes) ? trim($request->next_ape_apes) : '');
        $dataTempatUsaha->expired_perijinan_lain = (isset($request->expired_perijinan_lain) ? trim($request->expired_perijinan_lain) : '');
        $dataTempatUsaha->next_perijinan_lain = (isset($request->next_perijinan_lain) ? trim($request->next_perijinan_lain) : '');
        $dataTempatUsaha->expired_perijinan_lain_1 = (isset($request->expired_perijinan_lain_1) ? trim($request->expired_perijinan_lain_1) : '');
        $dataTempatUsaha->next_perijinan_lain_1 = (isset($request->next_perijinan_lain_1) ? trim($request->next_perijinan_lain_1) : '');
        $dataTempatUsaha->expired_perijinan_lain_2 = (isset($request->expired_perijinan_lain_2) ? trim($request->expired_perijinan_lain_2) : '');
        $dataTempatUsaha->next_perijinan_lain_2 = (isset($request->next_perijinan_lain_2) ? trim($request->next_perijinan_lain_2) : '');
        $dataTempatUsaha->desc = $request->desc;
        
        $arrdata = array(
            'upddate' => Carbon::now(),
            'content_datatmpusaha' => json_encode($dataTempatUsaha)
        );

        $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Sukses simpan data';

        return response()->json($this->output);
    } 

    public function inquiryListPengajuanKreditDeptHeadCra(Request $request)
    {
        
        if (empty($request->tp_produk) || !isset($request->tp_produk)) {
            throw new ParameterException("Parameter tp_produk tidak valid");
        }
        if (empty($request->region) || !isset($request->region)) {
            throw new ParameterException("Parameter region tidak valid");
        }
        if (empty($request->page) || !isset($request->page)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        if (empty($request->limit) || !isset($request->limit)) {
            throw new ParameterException("Parameter limit tidak valid");
        }

        $tp_produk = isset($request->tp_produk) && !empty($request->tp_produk) ? [$request->tp_produk] : ["50"];

        $filter = [
            'userid_ao'     => isset($request->userid_ao) ? $request->userid_ao : '',
            'nama_debitur'  => isset($request->nama_debitur) ? $request->nama_debitur : '',
            'branch'        => isset($request->branch) ? $request->branch : '',
            'pn_cra'        => $request->pn_cra,
            'region'        => $request->region,
            'tp_produk_in'  => $tp_produk,
            'limit'         => $request->limit,
            'page'          => $request->page,
            'status'        => isset($request->status) ? $request->status : '',
            'status_in'     => isset($request->status_in) ? $request->status_in : '',
        ];

        $list_prakarsa = $list_produk = $list_status = [];
        $get_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        $get_produk = $this->prakarsamediumRepo->getProdukBySegment($segment = 'sme');
        if (!empty($get_produk->toArray())) {
            foreach ($get_produk as $produk) {
                $list_produk[$produk->tp_produk] = $produk->nama_produk;
            }
        }

        $get_status = $this->prakarsamediumRepo->getStatus();
        if (!empty($get_status->toArray())) {
            foreach ($get_status as $status) {
                $list_status[$status->id] = $status->keterangan;
            }
        }

        foreach ($get_prakarsa->toArray() as $v_prakarsa) {
            $tp_produk = in_array($v_prakarsa->tp_produk, array_keys($list_produk)) ? $list_produk[$v_prakarsa->tp_produk] : "-";
            $v_status = in_array($v_prakarsa->status, array_keys($list_status)) ? $list_status[$v_prakarsa->status] : "-";

            if ($v_prakarsa->tp_produk == "49" && $v_prakarsa->status == "31") {
                $v_status = "Review Pinca";
            } else if ($v_prakarsa->tp_produk == "50" && $v_prakarsa->status == "31") {
                $v_status = "Review Dept Head";
            }

            $prakarsa = new stdClass();
            $prakarsa->refno        = $v_prakarsa->refno;
            $prakarsa->nama_debitur = $v_prakarsa->nama_debitur;
            $prakarsa->userid_ao    = $v_prakarsa->userid_ao;
            $prakarsa->nama_ao      = $v_prakarsa->nama_ao;
            $prakarsa->branch       = $v_prakarsa->branch;
            $prakarsa->nama_branch  = $v_prakarsa->nama_branch;
            $prakarsa->produk       = $tp_produk;
            $prakarsa->status       = $v_prakarsa->status;
            $prakarsa->status       = $v_status;

            array_push($list_prakarsa, $prakarsa);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry pengajuan kredit';
        $this->output->responseData = $list_prakarsa;

        return response()->json($this->output);
    }

    public function inquiryMonitoringPutusan(Request $request)
    {
        
        if (empty($request->page) || !isset($request->page)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        if (empty($request->limit) || !isset($request->limit)) {
            throw new ParameterException("Parameter limit tidak valid");
        }

        $filter = [
            'userid_ao'     => isset($request->userid_ao) ? $request->userid_ao : '',
            'nama_debitur'  => isset($request->nama_debitur) ? $request->nama_debitur : '',
            'branch'        => isset($request->branch) ? $request->branch : '',
            'branch_in'        => isset($request->branch_in) ? $request->branch_in : '',
            'region'        => $request->region,
            'tp_produk_in'  => $request->tp_produk_in,
            'tp_produk'  => $request->tp_produk,
            'limit'         => $request->limit,
            'content_data'  => ['content_datafinansial'],
            'page'          => $request->page,
            'status'        => isset($request->status) ? $request->status : '',
            'status_in'        => isset($request->status_in) ? $request->status_in : '',
        ];

        $list_prakarsa = $list_produk = $list_status = [];
        $get_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa tidak ditemukan");
        }

        $get_produk = $this->prakarsamediumRepo->getProdukBySegment($segment = 'sme');
        if (!empty($get_produk->toArray())) {
            foreach ($get_produk as $produk) {
                $list_produk[$produk->tp_produk] = $produk->nama_produk;
            }
        }

        $get_status = $this->prakarsamediumRepo->getStatus();
        if (!empty($get_status->toArray())) {
            foreach ($get_status as $status) {
                $list_status[$status->id] = $status->keterangan;
            }
        }
        
        foreach ($get_prakarsa->toArray() as $v_prakarsa) {
            $finansial = ($v_prakarsa->content_datafinansial);
           
            $khtpk = isset($finansial->total_exposure_khtpk) ? $finansial->total_exposure_khtpk : '';
            
            $tp_produk = in_array($v_prakarsa->tp_produk, array_keys($list_produk)) ? $list_produk[$v_prakarsa->tp_produk] : "-";
            $v_status = in_array($v_prakarsa->status, array_keys($list_status)) ? $list_status[$v_prakarsa->status] : "-";

            $prakarsa = new stdClass();
            $prakarsa->refno                  = $v_prakarsa->refno;
            $prakarsa->nama_debitur           = $v_prakarsa->nama_debitur;
            $prakarsa->userid_ao              = $v_prakarsa->userid_ao;
            $prakarsa->nama_ao                = $v_prakarsa->nama_ao;
            $prakarsa->branch                 = $v_prakarsa->branch;
            $prakarsa->nama_branch            = $v_prakarsa->nama_branch;
            $prakarsa->produk                 = $tp_produk;
            $prakarsa->status                 = $v_prakarsa->status;
            $prakarsa->total_exposure         = (string)$khtpk;
            $prakarsa->status                 = $v_status;
            
            array_push($list_prakarsa, $prakarsa);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry monitoring putusan';
        $this->output->responseData = $list_prakarsa;

        return response()->json($this->output);
    }

    public function inquiryJawabanQca(Request $request)
    {
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (!isset($request->tp_produk) && !in_array($request->tp_produk, ['49', '50'])) {
            throw new ParameterException("Parameter tp produk salah");
        }
        
        if (!isset($request->jabatan) && !in_array($request->jabatan, ['RM', 'CRA'])) {
            throw new ParameterException("Parameter jabatan salah");
        }
        
        $prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, ['content_datanonfinansial']);
        
        $content_datanonfinansial = !empty($prakarsa->content_datanonfinansial) ? json_decode((String) $prakarsa->content_datanonfinansial) : [];
        
        if ($request->jabatan == 'RM'){
            if (!isset($content_datanonfinansial->jawaban_qca_rm) || empty($content_datanonfinansial->jawaban_qca_rm)) {
                throw new InvalidRuleException("RM Belum melakukan pengisian QCA");
            }

            $dataQca = new stdClass();
            $dataQca->jawaban_qca_rm = $content_datanonfinansial->jawaban_qca_rm;

            foreach ($dataQca->jawaban_qca_rm as $qca => $value){

                if ($value->lampiran != '' || $value->lampiran != null) {

                    $path_folder = !empty($prakarsa->path_folder) ? $prakarsa->path_folder : NULL;
                    
                    if (!is_dir($path_folder)) {
                        // mkdir($path_folder, 0775, true);
                    }
                    $current_lampiran = str_replace("/storage/emulated/0/Android/data/id.co.bri.brispotnew/files/Pictures/", "", $value->lampiran);

                    $explode_lampiran = explode("/", $current_lampiran);

                    array_shift($explode_lampiran);
                    $new_lampiran = implode('/', $explode_lampiran);

                    $new_path_to_folder = $path_folder . $new_lampiran;

                    $hash_link = Str::random(40);
                    $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public') . '/securelink/' . $hash_link;
                    exec($symlink_url);
                    $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link . '/';

                    if (file_exists($new_path_to_folder)) {
                        $value->lampiran = $url_file . $new_lampiran;
                    }
                   
                } else{
                    $value->lampiran = '';
                }
            }

        } elseif ($request->jabatan == 'CRA'){
            if (!isset($content_datanonfinansial->jawaban_qca_cra) || empty($content_datanonfinansial->jawaban_qca_cra)) {
                throw new InvalidRuleException("CRA Belum melakukan pengisian QCA");
            }
            $dataQca = new stdClass();
            $dataQca->jawaban_qca_cra = $content_datanonfinansial->jawaban_qca_cra;
        }
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry jawaban qca';
        $this->output->responseData = $dataQca;

        return response()->json($this->output);
    }

    public function inquiryPersyaratanKredit(Request $request)
    {
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $filter = [
            'refno' => $request->refno, 
            'content_data' => ['content_syarat_perjanjian']
        ];

        $getData = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);

        $syaratKredit = json_decode($getData->content_syarat_perjanjian);

        foreach($syaratKredit as $index => $value){
            $keterangan = [];
            $value_index = array_filter(array_keys((array) $value), 'is_numeric');
            // $min = min($value_index);
            // $max = max($value_index);
            
            foreach ($value_index as $datas) {
                array_push($keterangan, $value->$datas->keterangan);
                unset($value->$datas);
            }

            $value->keterangan = $keterangan;
        }
        
        if (empty($getData->toArray())) {
            throw new DataNotFoundException("Data tidak ditemukan");
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry persyaratan kredit';
        $this->output->responseData = $syaratKredit;

        return response()->json($this->output);
    }

    public function simpanKancaBookingOffice(Request $request)
    {
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        
        if (empty($request->kode_uker_bo) || !isset($request->refno)) {
            throw new ParameterException("Parameter kode_uker_bo tidak valid");
        }
        
        if (empty($request->nama_uker_bo) || !isset($request->nama_uker_bo)) {
            throw new ParameterException("Parameter nama_uker_bo tidak valid");
        }
        
        $content_data = [
            'content_datapengajuan'
        ];

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }

        $content_datapengajuan = $get_prakarsa->content_datapengajuan != "" && !is_array($get_prakarsa->content_datapengajuan) ? json_decode((string) $get_prakarsa->content_datapengajuan, true) : [];

        $new_content_datapengajuan = new DataPengajuanMenengah($content_datapengajuan);
        $new_content_datapengajuan->kode_uker_bo = $request->kode_uker_bo;
        $new_content_datapengajuan->nama_uker_bo = $request->nama_uker_bo;
        
        $arrdata = array(
            'content_datapengajuan' => $new_content_datapengajuan,
            'status' => '100'
        );
        
        $update_prakarsa = $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil simpan data';

        return response()->json($this->output);
    }

    public function inquiryDataApproval(Request $request)
    {
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        if (!isset($request->tipe) && !in_array($request->tipe, ['pemutus', 'pemrakarsa_tambahan'])) {
            throw new ParameterException("Isian tipe salah");
        }

        $getPrakarsa = $this->prakarsamediumRepo->getPrakarsa($request->refno);
        
        if (empty($getPrakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        $newArray = [];
        if (empty($getPrakarsa->approval)) {
            throw new DataNotFoundException("Data approval tidak ditemukan");
        }
        foreach($request->tipe as $data){
            foreach($getPrakarsa->approval as $value){
                $newApproval = new stdClass();
                if(isset($value->tipe) && $value->tipe == $data){
                    $newApproval->pn = $value->pn;
                    $newApproval->nama = $value->nama;
                    $newApproval->tipe = $value->tipe;
                    $newApproval->flag_putusan = $value->flag_putusan;
                    $newApproval->tgl_putusan = Carbon::parse($value->tgl_putusan)->format('Y-m-d');
                    $newArray[$data][] = $newApproval;
                }
            }
        }

        $status_putusan = "";
        $listPutusan = [];
        foreach($newArray['pemutus'] as $data2){
            array_push($listPutusan, $data2->flag_putusan);
            
        }
        $status_putusan = array_count_values($listPutusan);
        
        $hasil_status_putusan = '';
        if(!empty($status_putusan['']) && !empty($status_putusan['2'])){
            $hasil_status_putusan = 'Ditolak';

        } else if(!empty($status_putusan['']) && !empty($status_putusan['1'])){
            $hasil_status_putusan = 'Menunggu Putusan';

        } else if(!empty($status_putusan['1']) && !empty($status_putusan['2'])){
            $hasil_status_putusan = 'Ditolak';

        } else if(!empty($status_putusan['']) && !empty($status_putusan['1']) && !empty($status_putusan['2'])){
            $hasil_status_putusan = 'Ditolak';

        } else if(!empty($status_putusan['']) && empty($status_putusan['1']) && empty($status_putusan['2']) ){
            $hasil_status_putusan = 'Menunggu Putusan';

        } else if(!empty($status_putusan[''])){
            $hasil_status_putusan = 'Menunggu Putusan';

        } else if(!empty($status_putusan['1'])){
            $hasil_status_putusan = 'Disetujui';

        } else if(!empty($status_putusan['2'])){
            $hasil_status_putusan = 'Ditolak';
        }
       
        $dataApproval = new stdClass();
        $dataApproval->jenis_putusan = $getPrakarsa->override == 'N' ? 'Normal' : 'Override';
        $dataApproval->status_putusan = $hasil_status_putusan;
        $dataApproval->approval = $newArray;
        
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil inquiry data approval';
        $this->output->responseData = $dataApproval;

        return response()->json($this->output);
    }

    public function simpanTotalExposure(Request $request)
    {
        if (empty($request->pn) || !isset($request->pn)) {
            throw new ParameterException("Parameter page tidak valid");
        }
        
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        
        if (empty($request->total_exposure) || !isset($request->total_exposure)) {
            throw new ParameterException("Parameter total_exposure tidak valid");
        }
        
        $content_data = [
            'content_datafinansial'
        ];

        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno, $content_data);

        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah");
        }

        $content_datafinansial = $get_prakarsa->content_datafinansial != "" && !is_array($get_prakarsa->content_datafinansial) ? json_decode((string) $get_prakarsa->content_datafinansial, true) : [];

        $new_content_datafinansial = new DataFinansialMenengah($content_datafinansial);
        $new_content_datafinansial->total_exposure = $request->total_exposure;
        
        $arrdata = array(
            'content_datafinansial' => $new_content_datafinansial,
            'plafond' => $request->total_plafond,
        );
        
        $update_prakarsa = $this->prakarsamediumRepo->updatePrakarsa($request->refno, $arrdata);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil simpan data total eksposure';

        return response()->json($this->output);
    }

    public function putusSepakatKCBO(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }
        if (!isset($request->id_aplikasi) || empty($request->id_aplikasi)) {
            throw new ParameterException("Parameter id_aplikasi tidak valid");
        }
        if (!isset($request->pn) || empty($request->pn)) {
            throw new ParameterException("Parameter pn tidak valid");
        }
        if (!isset($request->uid) || empty($request->uid)) {
            throw new ParameterException("Parameter uid tidak valid");
        }
        if (!isset($request->kcbo) || empty($request->kcbo)) {
            throw new ParameterException("Parameter kcbo tidak valid");
        }
        if (!isset($request->catatan) || empty($request->catatan)) {
            throw new ParameterException("Parameter catatan tidak valid");
        }
        if (!isset($request->nama_pemutus) || empty($request->nama_pemutus)) {
            throw new ParameterException("Parameter nama_pemutus tidak valid");
        }

        $new_status = 0;
        $get_prakarsa = $this->prakarsa_repo->getPrakarsa($request->refno);

        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }
        
        if ($request->id_aplikasi != $get_prakarsa->id_aplikasi) {
            throw new InvalidRuleException("ID aplikasi tidak ditemukan");
        }

        if ($get_prakarsa->status != 100) {
            throw new InvalidRuleException("Status prakarsa tidak sesuai");
        }

        $approval = !empty($get_prakarsa->approval) ? json_decode((String) $get_prakarsa->approval) : [];
        
        $newApproval2 = [];
        $newApproval2['pn_pemutus']         = $request->pn;
        $newApproval2['nama_pemutus']       = $request->nama_pemutus;
        $newApproval2['tipe']               = 'pemutus_adk_kcbo';
        $newApproval2['jabatan_pemutus']    = $request->jabatan_pemutus;
        $newApproval2['tgl_putusan']        = Carbon::now()->toDateTimeString();
        $newApproval2['catatan_pemutus']    = $request->catatan;
        
        array_push($approval, $newApproval2);

        $param              = new stdClass();
        $param->id_aplikasi = $request->id_aplikasi;
        $param->uid         = $request->uid;
        $param->catatan     = $request->catatan;
        $param->kcbo        = $request->kcbo;

        $clientResponse     = $client->call($param, env('BRISPOT_EKSTERNAL_URL'), '/las/putusSepakatKCBO');
        
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode == "00") {
            $new_status = 105;
            $new_status > 0 ? $get_prakarsa->status = $new_status : '';
            $new_approval = new ApprovalCollection($approval);
            $get_prakarsa->approval = $new_approval->toJson();
        
            $update_prakarsa = $this->prakarsa_repo->updatePrakarsa($get_prakarsa);

            if ($update_prakarsa == 0) {
                throw new InvalidRuleException("Update prakarsa gagal");
            }
            
        } else {
            throw new ThirdPartyServiceException("gagal kirim putusan");
        }

        // produce kafka putusan
        if (in_array($get_prakarsa->status, [105])) {
            $produceKafkaPutusan = $this->produceKafkaPutusan($get_prakarsa->refno);
        }

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil kirim putusan';

        return response()->json($this->output);
    }

    function generateQrcode(Array $data)
    {
        $writer = new PngWriter();

        // Create QR code
        $qrCode = QrCode::create($data['message'])
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelLow())
            ->setSize(300)
            ->setMargin(10)
            ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $resultQrCode = $writer->write($qrCode);

        return $resultQrCode->getDataUri();
    }

    public function kirimPengajuanScoringCrr(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_datacrs']
        ];

        $get_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Prakarsa dengan refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa[0]->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }
        
        if (!empty($get_prakarsa[0]->content_datacrs) && !empty($get_prakarsa[0]->crs)) {
            throw new InvalidRuleException("Prakarsa dengan refno:" . $request->refno . " sudah memiliki data crs");
        }

        $arrdata = array(
            'id_aplikasi' => $get_prakarsa[0]->id_aplikasi,
            'refno' => $get_prakarsa[0]->refno,
            'tp_produk' => $get_prakarsa[0]->tp_produk,
            'status' => '0',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        );
       
        $update_prakarsa = $this->prakarsamediumRepo->updatePengajuanScoringCrr($arrdata, $request->refno);

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil kirim pengajuan scoring crr';
        $this->output->responseData = [
            'id_aplikasi' => $get_prakarsa[0]->id_aplikasi,
            'created_at' => Carbon::now()->toDateTimeString()
            
        ];

        return response()->json($this->output);
    }

    public function generateScoringCrr(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $filter = [
            'refno' => $request->refno,
            'content_data' => ['content_datacrs']
        ];

        $get_prakarsa = $this->prakarsamediumRepo->getListPrakarsa($filter);
        
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("prakarsa dengan refno:" . $request->refno . " tidak ditemukan");
        }

        if (!in_array($get_prakarsa[0]->tp_produk, ['49', '50'])) {
            throw new InvalidRuleException("Prakarsa bukan segmen menengah / upper small");
        }

        $refno = $get_prakarsa[0]->refno;
        
        $getPengajuanCrr = $this->prakarsamediumRepo->getDataPengajuanCrr($request->refno);
       
        if (empty($getPengajuanCrr->toArray())) {
            throw new DataNotFoundException("Scoring Crr dengan refno:" . $request->refno . " tidak ditemukan");
        }

        foreach($getPengajuanCrr as $value){
            $data_pengajuan = new stdClass;
            $data_pengajuan->id_aplikasi = $value->id_aplikasi;
            $data_pengajuan->refno = $value->refno;
            $data_pengajuan->tp_produk = $value->tp_produk;
            $data_pengajuan->content_datacrs = isset($value->content_datacrs) && !empty($value->content_datacrs) ? json_decode($value->content_datacrs, true) : [];
            $data_pengajuan->crs = $value->crs;
            $data_pengajuan->status = $value->status;
            $data_pengajuan->status_ket = $value->status == '2' ? 'Berhasil generate data scoring crr' : "Menunggu hasil generate CRR";
        }
    
        $arrdata = array(
            'updated_at' => Carbon::now()->toDateTimeString()
        );
       
        $update_prakarsa = $this->prakarsamediumRepo->updatePengajuanScoringCrr($arrdata, $request->refno);

        $responseData = $data_pengajuan;

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil';
        $this->output->responseData = $responseData;

        return response()->json($this->output);
    } 

    public function insertScoringCrr(Request $request)
    {
        if (empty($request->refno) || !isset($request->refno)) {
            throw new ParameterException("Parameter refno tidak valid");
        }

        $getPengajuanCrr = $this->prakarsamediumRepo->getDataPengajuanCrr($request->refno);
       
        if (empty($getPengajuanCrr->toArray())) {
            throw new DataNotFoundException("Data Crr dengan refno:" . $request->refno . " tidak ditemukan");
        }

        foreach($getPengajuanCrr as $value){
            $data_pengajuan = new stdClass;
            $data_pengajuan->content_datacrs = isset($value->content_datacrs) && !empty($value->content_datacrs) ? json_decode($value->content_datacrs, true) : [];
            $data_pengajuan->crs = $value->crs;
            $data_pengajuan->refno = $value->refno;
        }
        
        $dataCrs = new DataCrsMenengahCollection($data_pengajuan->content_datacrs);
        
        $arrPrakarsa = array(
            'content_datacrs' => json_encode($dataCrs),
            'crs' => $data_pengajuan->crs,
            'upddate' => Carbon::now()->toDateTimeString(),
        );
        $update_prakarsa = $this->prakarsamediumRepo->updatePrakarsa($data_pengajuan->refno, $arrPrakarsa);
        if ($update_prakarsa == 0) {
            throw new InvalidRuleException("Update prakarsa gagal");
        }
       
        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil update data crs';

        return response()->json($this->output);
    }

    public function generateFormAgunan(Request $request, RestClient $client){
        
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak boleh kosong");
        }

        $content_data = [
            "content_datarekomendasi",
            "content_dataagunan"
        ];

        $get_prakarsa = $this->prakarsamediumRepo->getPrakarsa($request->refno, $content_data);

        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Inquiry prakarsa not found");
        }

        $data['detail_prakarsa'] = $get_prakarsa;
        $data['detail_uker'] = $this->prakarsamediumRepo->getBranch($data['detail_prakarsa']->branch);
        
        $data['drop_bentuk_pengikatan'] = array("01" => "Hak Tanggunan", "02" => "Gadai", "03" => "FEO", "04" => "SKMHT", "05" => "Cessie", "06" => "Belum diikat", "09" => "Lain lain", "10" => "Fidusia dengan UU", "11" => "Fidusia dengan PJ.08");

        $param_agunan = $this->prakarsamediumRepo->getDataParamAgunan();
        $data['param_agunan'] = array();
        foreach ($param_agunan as $res_agunan) {
            $data['param_agunan'][$res_agunan->param]['persen_nl'] = $res_agunan->persen_nl;
            $data['param_agunan'][$res_agunan->param]['persen_pnpw'] = $res_agunan->persen_pnpw;
        }

        if (in_array('content_datarekomendasi', $content_data) && PrakarsaFacade::checkJSON($get_prakarsa->content_datarekomendasi) == true) {
            $get_prakarsa->content_datarekomendasi = json_decode($get_prakarsa->content_datarekomendasi, false, 512, JSON_FORCE_OBJECT);
        }

        if (!empty($get_prakarsa->content_datarekomendasi)) {
            $convertRekomendasi = new StdClass();
            $rekomenPrakarsa = $rekomenAdk = array();
            $decodeContentRekomendasi = $get_prakarsa->content_datarekomendasi;
            foreach ($decodeContentRekomendasi as $keyDecodeRekomendasi => $rowDecodeRekomendasi) {
                if (is_numeric($keyDecodeRekomendasi)) {
                    $index = "idx" . $rowDecodeRekomendasi['id_kredit'];
                    $rekomenPrakarsa[$index] = $rowDecodeRekomendasi;
                } elseif ($keyDecodeRekomendasi == "rekomenAdk") {
                    foreach ($rowDecodeRekomendasi as $keyRekomenAdk => $rowRekomenAdk) {
                        $index = "idx" . $rowRekomenAdk['id_kredit'];
                        $rekomenAdk[$index] = $rowRekomenAdk;
                    }
                } else {
                    $convertRekomendasi->$keyDecodeRekomendasi = $rowDecodeRekomendasi;
                }
            }
            $datarekomendasi = $get_prakarsa->content_datarekomendasi;

            $datarekomendasi['rekomenPrakarsa'] = $rekomenPrakarsa;
            $datarekomendasi['rekomenAdk'] = $rekomenAdk;

            $get_prakarsa->content_datarekomendasi = $datarekomendasi;
        }
    
        $data['det_rekomendasi'] = $datarekomendasi;
        $data['det_agunan'] = isset($get_prakarsa->content_dataagunan) ? json_decode($get_prakarsa->content_dataagunan->toJson()) : [];
       
        foreach($data['det_agunan'] as $key => $val){
            $nilai_pasar_wajar_terbilang = PrakarsaFacade::terbilang($val->nilai_pasar_wajar);
            $val->nilai_pasar_wajar_terbilang =  $nilai_pasar_wajar_terbilang;
            
        }
        
        $get_pekerja = $client->call(
            [
                "pn"=> $get_prakarsa->pn_pemrakarsa
            ]
            , env('BRISPOT_MASTER_URL'), '/v1/inquiryPekerja'); 
           
        if (isset($get_pekerja->responseCode) && $get_pekerja->responseCode != "00") {
            throw new DataNotFoundException("Data pekerja tidak ditemukan");
        }
        
        $data['nama_ao'] = $get_pekerja->responseData->sname;
        $data['jabatan'] = $get_pekerja->responseData->htext;
       
        Queue::pushOn(env('QUEUE_NAME_RITEL'), new generateFormAgunanJob($data));
        
        $path_file = !empty($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;
        $path_folder = !empty($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : NULL;
        $documentFileName = "laporan_penilaian_agunan.pdf";
        
        if (empty($path_folder)) {
            throw new DataNotFoundException("Path folder tidak ditemukan");
        }

        if (empty($path_file)) {
            throw new DataNotFoundException("Path file tidak ditemukan");
        }

        $hash_link = Str::random(10);
        if (!is_dir($path_folder)) {
            mkdir($path_folder, 0775, true);
        }
        $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
        exec($symlink_url);
        $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link;
        
        $new_url = '';
        $keterangan = '';
        $flag = '';
        if (!empty($path_file->cetakan) && in_array($documentFileName, $path_file->cetakan)) {
            
            $flag = "1";
            $keterangan = "Berhasil generate dokumen agunan";
            $new_url = $url_file . '/cetakan/' .'laporan_penilaian_agunan.pdf';

        } else {
            $flag = "0";
            $keterangan = "Mohon menunggu proses generate dokumen agunan belum selesai";
            $new_url = "Link download belum tersedia";
        }

        $responseData = new stdClass();
        $responseData->flag = $flag;
        $responseData->keterangan = $keterangan;
        $responseData->url_file = $new_url;

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil';
        $this->output->responseData = $responseData;

        return response()->json($this->output);
    }

    public function generateMABMenengah2(Request $request, RestClient $client)
    {
        if (!isset($request->refno) || empty($request->refno)) {
            throw new ParameterException("Parameter refno tidak boleh kosong");
        }

        $content_data = [
            "content_datapribadi",
            "content_datadebiturlas",
            "content_datatmpusaha",
            "content_dataagunan",
            "content_datanonfinansial",
            "content_datapengajuan",
            "content_datafinansial",
            "content_dataprescreening",
            "content_datarekomendasi",
            "content_datacrs"
        ];

        $get_prakarsa = $this->prakarsamediumRepo->getPrakarsa($request->refno, $content_data);
        if (empty($get_prakarsa->toArray())) {
            throw new DataNotFoundException("Inquiry prakarsa not found");
        }

        if (in_array('content_datarekomendasi', $content_data) && PrakarsaFacade::checkJSON($get_prakarsa->content_datarekomendasi) == true) {
            $get_prakarsa->content_datarekomendasi = json_decode($get_prakarsa->content_datarekomendasi, false, 512, JSON_FORCE_OBJECT);
        }

        if (!empty($get_prakarsa->content_datarekomendasi)) {
            $convertRekomendasi = new StdClass();
            $rekomenPrakarsa = $rekomenAdk = array();
            $decodeContentRekomendasi = $get_prakarsa->content_datarekomendasi;
            foreach ($decodeContentRekomendasi as $keyDecodeRekomendasi => $rowDecodeRekomendasi) {
                if (is_numeric($keyDecodeRekomendasi)) {
                    $index = "idx" . $rowDecodeRekomendasi['id_kredit'];
                    $rekomenPrakarsa[$index] = $rowDecodeRekomendasi;
                } elseif ($keyDecodeRekomendasi == "rekomenAdk") {
                    foreach ($rowDecodeRekomendasi as $keyRekomenAdk => $rowRekomenAdk) {
                        $index = "idx" . $rowRekomenAdk['id_kredit'];
                        $rekomenAdk[$index] = $rowRekomenAdk;
                    }
                } else {
                    $convertRekomendasi->$keyDecodeRekomendasi = $rowDecodeRekomendasi;
                }
            }
            $datarekomendasi = $get_prakarsa->content_datarekomendasi;

            $datarekomendasi['rekomenPrakarsa'] = $rekomenPrakarsa;
            $datarekomendasi['rekomenAdk'] = $rekomenAdk;

            $get_prakarsa->content_datarekomendasi = $datarekomendasi;
        }

        if (!in_array($get_prakarsa->tp_produk, ['49', '50'])) {
            throw new DataNotFoundException("Prakarsa dengan refno ".$request->refno." bukan segmen menengah");
        }

        $data_prakarsa              = $get_prakarsa;
        $data_prakarsa->pn_cra      = isset($get_prakarsa->pn_cra) ? $get_prakarsa->pn_cra : "";
        $data_prakarsa->nama_cra    = isset($get_prakarsa->nama_cra) ? $get_prakarsa->nama_cra : "";


        $filter = [
            'refno' => $request->refno,
            'content_data' => [
                'content_analisa_tambahan',
                'content_data_lainnya',
                'content_data_comment_cra',
                'content_ratio_keuangan',
                'content_data_lainnya',
                'content_data_asumsi',
                'content_syarat_perjanjian',
                'content_data_rekomendasi',
                'content_data_neraca',
                'content_data_labarugi',
                'content_data_kebutuhan_kredit'
            ]
        ];

        $pengelolaan_keuangan = $this->prakarsamediumRepo->getPengelolaanKeuanganByRefno($filter);

        // INQUIRY PRESCREENING RITEL
        $response_prescreen = $this->prakarsamediumRepo->getPrescreeningByRefno($request->refno);
        if (!empty($response_prescreen)) {
            $result_data = array();
            foreach ($response_prescreen as $row_data) {
                if ($row_data->selected == "1") {
                    array_unshift($result_data, $row_data);
                }else{
                    array_push($result_data, $row_data);
                }
            }
        }

        $hasil_prescreen = [];

        // Hasil Prescreening
        $get_bkpm = $this->prakarsamediumRepo->getHasilPrescreenBKPM(['refno' => $request->refno]);
        if (!empty($get_bkpm->toArray())) {
            $prescreen = new stdClass;
            $data = isset($get_bkpm[0]->data) ? json_decode($get_bkpm[0]->data) : '';
            $prescreen->kriteria    = isset($data->result) ? $data->result == '1' ? 'Tidak Termasuk / <s>Termasuk</s>' : '<s>Tidak Termasuk</s> / Termasuk' : '';
            $prescreen->status      = isset($data->result) ? $data->result == '1' ? 'Diterima / <s>Ditolak</s>' : '<s>Diterima</s> / Ditolak' : '';

            $hasil_prescreen['bkpm'] = $prescreen;
        }

        $get_dhn = $this->prakarsamediumRepo->getHasilPrescreenDHN(['refno' => $request->refno]);
        if (!empty($get_dhn->toArray())) {
            $prescreen = new stdClass;
            foreach ($get_dhn as $dhn) {
                $data = isset($dhn->data) ? json_decode($dhn->data) : '';
                if (isset($data->result) && $data->result == "1") {
                    $prescreen->kriteria    = '<s>Tidak Termasuk</s> / Termasuk';
                    $prescreen->status      = '<s>Diterima</s> / Ditolak';
                    break;
                }

                $prescreen->kriteria    = 'Tidak Termasuk / <s>Termasuk</s>';
                $prescreen->status      = 'Diterima / <s>Ditolak</s>';
            }

            $hasil_prescreen['dhn'] = $prescreen;
        }

        $get_ps = $this->prakarsamediumRepo->getHasilPrescreenPS(['refno' => $request->refno]);
        if (!empty($get_ps->toArray())) {
            $prescreen = new stdClass;
            $data = isset($get_ps[0]->data) ? json_decode($get_ps[0]->data) : '';
            $prescreen->kriteria    = isset($data->warna) && !empty($data->warna) ? $data->warna : '';
            $prescreen->status      = isset($data->result) && !empty($data->result) ? in_array($data->result, ['1', '2']) ? 'Diterima / <s>Overide</s>' : 'Overide' : '';
            $prescreen->status_override = isset($data->result) && !empty($data->result) ? in_array($data->result, ['1', '2']) ? 'Diterima / <s>Overide</s>' : '<s>Diterima</s> / Overide' : '';

            $hasil_prescreen['ps'] = $prescreen;
        }

        $get_slik = $this->prakarsamediumRepo->getHasilPrescreenSlik(['refno' => $request->refno, 'selected' => '1']);
        if (!empty($get_slik->toArray())) {
            $prescreen = new stdClass;
            $max_colect_1 = 'Kol 1 / <s>Kol 2</s> / <s>Kol 3 keatas</s>';
            $max_colect_2 = '<s>Kol 1</s> / Kol 2 / <s>Kol 3 keatas</s>';
            $max_colect_3 = '<s>Kol 1</s> / <s>Kol 2</s> / Kol 3 keatas';
            $status_max_colect_1 = 'Diterima / <s>Diterima dengan Catatan</s> / <s>Ditolak</s>';
            $status_max_colect_2 = '<s>Diterima</s> / Diterima dengan Catatan / <s>Ditolak</s>';
            $status_max_colect_3 = '<s>Diterima</s> / <s>Diterima dengan Catatan</s> / Ditolak';
            $max_colect = [];
            foreach ($get_slik as $slik_data) {
                $detail_slik = isset($slik_data->detail) && isset(json_decode($slik_data->detail)->FasilitasKredit) ? json_decode($slik_data->detail)->FasilitasKredit : [];
                foreach ($detail_slik as $detail_slik_data) {
                    array_push($max_colect, $detail_slik_data->kolektibilitas);
                }
            }


            if (!empty($max_colect)) {
                $max_colect = max($max_colect);
                switch ($max_colect) {
                    case $max_colect == 1:
                        $prescreen->kriteria    = $max_colect_1;
                        $prescreen->status      = $status_max_colect_1;
                        break;
                    case $max_colect == 2:
                        $prescreen->kriteria    = $max_colect_2;
                        $prescreen->status      = $status_max_colect_2;
                        break;
                    case $max_colect >= 3:
                        $prescreen->kriteria    = $max_colect_3;
                        $prescreen->status      = $status_max_colect_3;
                        break;

                    default:
                        break;
                }
            }

            $hasil_prescreen['slik'] = $prescreen;
        }

        $get_sicd = $this->prakarsamediumRepo->getHasilPrescreenSicd(['refno' => $request->refno, 'selected' => '1']);
        if (!empty($get_sicd->toArray())) {
            $sicd_kriteria_11 = 'Tidak / <s>Ya</s>';
            $sicd_kriteria_12 = '<s>Tidak</s> / Ya';
            $sice_status_11 = 'Diterima / <s>Ditolak</s>';
            $sice_status_12 = '<s>Diterima</s> / Ditolak';

            $sicd_kriteria_21 = 'Maks Kol 1 / <s>Maks Kol 2</s> / <s>Kol 3 ke atas</s>';
            $sicd_kriteria_22 = '<s>Maks Kol 1</s> / Maks Kol 2 / <s>Kol 3 ke atas</s>';
            $sicd_kriteria_23 = '<s>Maks Kol 1</s> / <s>Maks Kol 2</s> / Kol 3 ke atas';
            $sice_status_21 = 'Diterima / <s>Diterima dengan Catatan</s> / <s>Ditolak</s>';
            $sice_status_22 = '<s>Diterima</s> / Diterima dengan Catatan / <s>Ditolak</s>';
            $sice_status_23 = '<s>Diterima</s> / <s>Diterima dengan Catatan</s> / Ditolak';


            $max_sicd_1 = $max_sicd_2 = [];
            foreach ($get_sicd as $sicd) {
                $data_sicd = isset($sicd->data) ? json_decode($sicd->data) : [];
                isset($data_sicd->is_ph) && !empty($data_sicd->is_ph) ? array_push($max_sicd_1, $data_sicd->is_ph) : ''; // pernah dihapus dari BRI
                isset($data_sicd->bikole) && !empty($data_sicd->bikole) ? array_push($max_sicd_2, $data_sicd->bikole) : ''; // Kelektabilitas
            }

            if (!empty($max_sicd_1)) {
                $prescreen_sicd = new stdClass;
                $max_sicd_1 = max($max_sicd_1);
                $prescreen_sicd->kriteria   = $max_sicd_1 == "0" ? $sicd_kriteria_11 : $sicd_kriteria_12;
                $prescreen_sicd->status     = $max_sicd_1 == "0" ? $sice_status_11 : $sice_status_12;
                $hasil_prescreen['sicd_1']  = $prescreen_sicd;
            }

            if (!empty($max_sicd_2)) {
                $prescreen_sicd = new stdClass;
                $max_sicd_2 = max($max_sicd_2);
                switch ($max_sicd_2) {
                    case $max_sicd_2 == 1:
                        $prescreen_sicd->kriteria    = $sicd_kriteria_21;
                        $prescreen_sicd->status      = $sice_status_21;
                        break;
                    case $max_sicd_2 == 2:
                        $prescreen_sicd->kriteria    = $sicd_kriteria_22;
                        $prescreen_sicd->status      = $sice_status_22;
                        break;
                    case $max_sicd_2 >= 3:
                        $prescreen_sicd->kriteria    = $sicd_kriteria_23;
                        $prescreen_sicd->status      = $sice_status_23;
                        break;

                        default:
                        break;
                    }
                $hasil_prescreen['sicd_2']  = $prescreen_sicd;
            }
        }

        $get_sac = $this->prakarsamediumRepo->getHasilPrescreenSAC(['refno' => $request->refno]);
        if (!empty($get_sac->toArray())) {
            $prescreen = new stdClass;
            $data = isset($get_sac[0]->data) ? json_decode($get_sac[0]->data) : '';
            $prescreen->kriteria    = isset($data->flag_override) && !empty($data->flag_override) ? $data->flag_override == "Y" ? '<s>Kepatuhan di semua SAC</s> / Terdapat ketidakpatuhan' : 'Kepatuhan di semua SAC / <s>Terdapat ketidakpatuhan</s>' : '';
            $prescreen->status      = isset($data->flag_override) && !empty($data->flag_override) ? $data->flag_override == "Y" ? '<s>Diterima</s> / Overide' : 'Diterima / <s>Overide</s>' : '';
            $prescreen->status_override = isset($data->flag_override) && !empty($data->flag_override) ? $data->flag_override == "Y" ? '<s>Diterima</s> / Overide' : 'Diterima / <s>Overide</s>' : '';

            $hasil_prescreen['sac'] = $prescreen;
        }

        $get_kemendagri = $this->prakarsamediumRepo->getHasilPrescreenKemendagri(['refno' => $request->refno, 'nik' => $get_prakarsa->nik]);
        if (!empty($get_kemendagri->toArray())) {
            $prescreen = new stdClass;
            $data = isset($get_kemendagri[0]->data) ? json_decode($get_kemendagri[0]->data) : '';
            $prescreen->kriteria    = isset($data->namaKemendagri) ? !strpos($data->namaKemendagri, 'Tidak') ? 'Termasuk / <s>Tidak Termasuk</s>' : '<s>Termasuk</s> / Tidak Termasuk' : '';
            $prescreen->status      = isset($data->namaKemendagri) ? !strpos($data->namaKemendagri, 'Tidak') ? 'Diterima / <s>Diterima dengan catatan</s>' : '<s>Diterima</s> / Diterima dengan catatan' : '';

            $hasil_prescreen['kemendagri'] = $prescreen;
        }
        // End Hasil Prescreening

        // Tujuan penggunaan
        $response_tujuan_penggunaan = $this->prakarsamediumRepo->getTujuanPenggunaan();

        // bidang usaha
        $response_bidang_usaha = $this->prakarsamediumRepo->getAllBidangUsaha();

        // jabatan
        $response_jabatan = $this->prakarsamediumRepo->getAllJabatan();

        $found = false;
        $kolekKet = ['1' => 'Lancar', '2' => 'Dalam Perhatian Khusus', '3' => 'Kurang Lancar', '4' => 'Diragukan', '5' => 'Macet'];
        $konten_slik = isset($data_prakarsa->content_dataprescreening->hasil_slik) ? $data_prakarsa->content_dataprescreening->hasil_slik : [];
        $hasil_slik = array();
        if (!empty($konten_slik)) {
            foreach ($konten_slik as $value) {
                $hasil_slik[] = $value;
            }
        }

        // jawaban qca cra & rm
        $param              = new stdClass();
        $param->pn          = isset($request->userid_ao) ? $request->userid_ao : '00001111';
        $param->refno       = $request->refno;
        $param->tp_produk   = isset($get_prakarsa->tp_produk) ? $get_prakarsa->tp_produk : 50;
        $clientResponse     = $client->call($param, env('BRISPOT_PRAKARSA_URL'), '/v1/inquiryListBlindQCA');

        $jawaban_qca_rm_cra = [];
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode == "00") {
            $jawaban_qca_rm_cra = $clientResponse->responseData;
        }

        $total_agunan_pokok = $total_agunan_tambahan = 0;
        if (isset($data_prakarsa->content_dataagunan) && !empty($data_prakarsa->content_dataagunan)) {
            foreach (json_decode($data_prakarsa->content_dataagunan->toJson()) as $data_agunanx => $data_agunany) {
                isset($data_agunany->additional_content->kategori_agunan) && $data_agunany->additional_content->kategori_agunan == "pokok" ? $total_agunan_pokok++ : '';
                isset($data_agunany->additional_content->kategori_agunan) && $data_agunany->additional_content->kategori_agunan == "tambahan" ? $total_agunan_tambahan++ : '';
            }
        }

        // ppnd
        $ppnd = $this->prakarsamediumRepo->getPpnd($request->refno);

        $data_view = [
            'detail_prakarsa' => $data_prakarsa,
            'pengelolaan_keuangan' => $pengelolaan_keuangan,
            'ppnd' => $ppnd,
            'jawaban_qca_rm_cra'    => $jawaban_qca_rm_cra,
            'drop_bentuk_pengikatan' => [
                "01" => "HT",
                "02" => "Gadai",
                "03" => "FEO",
                "04" => "SKMHT",
                "05" => "Cessie",
                "06" => "Belum diikat",
                "09" => "Lain lain",
                "10" => "Fidusia dengan UU",
                "11" => "Fidusia dengan PJ.08"
            ],
            'total_agunan_pokok' => $total_agunan_pokok,
            'total_agunan_tambahan' => $total_agunan_tambahan
        ];
        $data_view['hasil_prescreening'] = $hasil_prescreen;

        // detail hasil bsa
        $param              = new stdClass();
        $param->refno       = $request->refno;
        $clientResponse     = $client->call($param, env('BRISPOT_PRAKARSA_URL'), '/v1/inquiryDetailHasilBSA');

        $list_validasi_bsa = [];
        if (isset($clientResponse->responseCode) && $clientResponse->responseCode == "00") {
            $responseData = $clientResponse->responseData;
            $list_validasi_bsa = isset($responseData->file_revisi_validasi_bsa) && !empty($responseData->file_revisi_validasi_bsa) ? $responseData->revisi_validasi_bsa : $responseData->list_validasi_bsa;
        }
        $data_view['detail_hasil_bsa'] = $list_validasi_bsa;


        if (!empty($result_data)) {
            $riwayatKredit = array();
            $slikNew = $result_data;
            $kunci = 1000;
            foreach ($slikNew as $detail_slik_1 => $slik) {
                $valueDetail = array();
                foreach ($hasil_slik as $hasil_slik_index => $value) {
                    if (isset($slik->detail) && $slik->detail != '') {
                        $slik_detail = json_decode($slik->detail);
                        if ($slik->selected == "1" && (isset($value['cbasid']) && $slik->id == $value['cbasid'])) {
                            $found = true;
                            if (isset($slik_detail->DataPokokDebitur) && $slik_detail->DataPokokDebitur != "") {
                                $arrDataPokokDebitur = [];
                                foreach ($slik_detail->DataPokokDebitur as $keys1 => $vals1) {
                                    $tmpFasilitasKredit = [];
                                    foreach ($slik_detail->FasilitasKredit as $key => $val) {
                                        foreach ($val as $keys => $vals) {
                                            if (strpos($keys, 'Kol') !== false && !empty($vals)) {
                                                $tmpFasilitasKredit['namaDebitur'] = $vals1->namaDebitur;
                                                $tmpFasilitasKredit['noIdentitas'] = $vals1->noIdentitas;
                                                $tmpFasilitasKredit['pelaporKet'] = isset($val->ljkKet) ? $val->ljkKet : $vals1->pelaporKet;
                                                $tmpFasilitasKredit['tanggalDibentuk'] = $vals1->tanggalDibentuk;
                                                $tmpFasilitasKredit['tanggalUpdate'] = $vals1->tanggalUpdate;
                                                $tmpFasilitasKredit['jenisPenggunaanKet'] = $val->jenisPenggunaanKet;
                                                $tmpFasilitasKredit['bakiDebet'] = $val->bakiDebet;
                                                $tmpFasilitasKredit['plafonAwal'] = $val->plafonAwal;
                                                $tmpFasilitasKredit['plafon'] = $val->plafon;
                                                $tmpFasilitasKredit['jumlahHariTunggakan'] = $val->jumlahHariTunggakan;
                                                $tmpFasilitasKredit['kondisiKet'] = $val->kondisiKet;
                                                $str = $keys;
                                                $number = preg_replace("/[^0-9]{1,4}/", '', $str);
                                                // $num[$key][] = $number . '-' . $vals;
                                                // sort($num[$key]);

                                                $tmpFasilitasKredit['last_kolektibilitas'] = $val->kolektibilitas;
                                                $tmpFasilitasKredit['last_kolektibilitas_ket'] = $kolekKet[$val->kolektibilitas];
                                            } else if (strpos($keys, 'history_tunggakan') !== false) {
                                                $tmpFasilitasKredit['namaDebitur'] = $slik_detail->DataPokokDebitur->namaDebitur;
                                                $tmpFasilitasKredit['noIdentitas'] = $slik_detail->DataPokokDebitur->noIdentitas;
                                                $tmpFasilitasKredit['pelaporKet'] = isset($val->ljkKet) ? $val->ljkKet : $vals1->pelaporKet;
                                                $tmpFasilitasKredit['tanggalDibentuk'] = $slik_detail->DataPokokDebitur->tanggalDibentuk;
                                                $tmpFasilitasKredit['tanggalUpdate'] = $slik_detail->DataPokokDebitur->tanggalUpdate;
                                                $tmpFasilitasKredit['jenisPenggunaanKet'] = $val->jenisPenggunaanKet;
                                                $tmpFasilitasKredit['bakiDebet'] = $val->bakiDebet;
                                                $tmpFasilitasKredit['plafonAwal'] = $val->plafonAwal;
                                                $tmpFasilitasKredit['plafon'] = $val->plafon;
                                                $tmpFasilitasKredit['jumlahHariTunggakan'] = $val->jumlahHariTunggakan;
                                                $tmpFasilitasKredit['kondisiKet'] = $val->kondisiKet;
                                                $str = $keys;
                                                $number = preg_replace("/[^0-9]{1,4}/", '', $str);
                                                $num[$key][] = $val->kolektibilitas . '-' . $val->kolektibilitasKet;
                                                sort($num[$key]);
                                                $mode = '2';

                                                $tmpFasilitasKredit['last_kolektibilitas'] = $val->kolektibilitas;
                                                $tmpFasilitasKredit['last_kolektibilitas_ket'] = $kolekKet[$val->kolektibilitas];
                                            }
                                            $arrDataPokokDebitur[$key] = $tmpFasilitasKredit;
                                        }
                                    }
                                }
                                $riwayatKredit[] = $arrDataPokokDebitur;
                            }
                            if (!isset($slik_detail->DataPokokDebitur)) {
                                $data_view['tglPenarikanRiwayatKredit'] = isset($slik_detail->posisiDataTerakhir) ? $slik_detail->posisiDataTerakhir : (isset($slik_detail->Header->posisiDataTerakhir) ? $slik_detail->Header->posisiDataTerakhir : null);
                                $riwayatKredit[$kunci][0]['namaDebitur'] = $value['nama'];
                                $kunci++;
                            }
                        }
                        if ($found == false) {
                            if ($slik->nik == '0000000000000000') {
                                $arrDataPokokDebitur = [];
                                foreach ($slik_detail->DataPokokDebitur as $keys1 => $vals1) {
                                    $tmpFasilitasKredit = [];
                                    foreach ($slik_detail->FasilitasKredit as $key => $val) {
                                        foreach ($val as $keys => $vals) {
                                            if (strpos($keys, 'Kol') !== false && !empty($vals)) {
                                                $tmpFasilitasKredit['namaDebitur'] = $vals1->namaDebitur;
                                                $tmpFasilitasKredit['noIdentitas'] = '';
                                                $tmpFasilitasKredit['pelaporKet'] = '';
                                                $tmpFasilitasKredit['tanggalDibentuk'] = '';
                                                $tmpFasilitasKredit['tanggalUpdate'] = '';
                                                $tmpFasilitasKredit['jenisPenggunaanKet'] = '';
                                                $tmpFasilitasKredit['bakiDebet'] = '';
                                                $tmpFasilitasKredit['plafonAwal'] = '';
                                                $tmpFasilitasKredit['plafon'] = '';
                                                $tmpFasilitasKredit['jumlahHariTunggakan'] = '';
                                                $tmpFasilitasKredit['kondisiKet'] = '';
                                                $str = $keys;
                                                $number = preg_replace("/[^0-9]{1,4}/", '', $str);
                                                $num[$key][] = $number . '-' . $vals;
                                                sort($num[$key]);
                                            } else if (strpos($keys, 'history_tunggakan') !== false) {
                                                $tmpFasilitasKredit['namaDebitur'] = $slik->detail->DataPokokDebitur->namaDebitur;
                                                $tmpFasilitasKredit['noIdentitas'] = $slik->detail->DataPokokDebitur->noIdentitas;
                                                $tmpFasilitasKredit['pelaporKet'] = $slik->detail->DataPokokDebitur->pelaporKet;
                                                $tmpFasilitasKredit['tanggalDibentuk'] = $slik->detail->DataPokokDebitur->tanggalDibentuk;
                                                $tmpFasilitasKredit['tanggalUpdate'] = $slik->detail->DataPokokDebitur->tanggalUpdate;
                                                $tmpFasilitasKredit['jenisPenggunaanKet'] = $val->jenisPenggunaanKet;
                                                $tmpFasilitasKredit['bakiDebet'] = $val->bakiDebet;
                                                $tmpFasilitasKredit['plafonAwal'] = $val->plafonAwal;
                                                $tmpFasilitasKredit['plafon'] = $val->plafon;
                                                $tmpFasilitasKredit['jumlahHariTunggakan'] = $val->jumlahHariTunggakan;
                                                $tmpFasilitasKredit['kondisiKet'] = $val->kondisiKet;
                                                $str = $keys;
                                                $number = preg_replace("/[^0-9]{1,4}/", '', $str);
                                                $num[$key][] = $val->kolektibilitas . '-' . $val->kolektibilitasKet;
                                                sort($num[$key]);
                                                $mode = '2';
                                            }
                                            $arrDataPokokDebitur[$key] = $tmpFasilitasKredit;
                                        }
                                    }
                                }
                                $data_view['nik_null'] = 'flag';
                                $riwayatKredit[] = $arrDataPokokDebitur;
                            }
                        }
                    }
                }
            }

            // if (isset($num) && $num != "") {
            //     foreach ($num as $keyIndex => $value) {
            //         $ket = end($value);
            //         $ketLast = substr($ket, 0, 1);
            //         $riwayatKredit[$keyIndex]['last_kolektibilitas'] = $ketLast;
            //         $riwayatKredit[$keyIndex]['last_kolektibilitas_ket'] = $kolekKet[$ketLast];
            //     }
            //     if (isset($mode) && $mode == '2') {
            //         foreach ($num as $keyIndex => $value) {
            //             $ket = end($value);
            //             $number = substr($ket, 0, 1);
            //             $ketLast = substr($ket, 2);
            //             $riwayatKredit[$keyIndex]['last_kolektibilitas'] = $number;
            //             $riwayatKredit[$keyIndex]['last_kolektibilitas_ket'] = $ketLast;
            //         }
            //     }
            // }

            $data_view['riwayatKredit'] = isset($riwayatKredit) ? $riwayatKredit : '';
            $data_view['tglPenarikanRiwayatKredit'] = isset($slik_detail->posisiDataTerakhir) ? $slik_detail->posisiDataTerakhir : (isset($slik_detail->Header->posisiDataTerakhir) ? $slik_detail->Header->posisiDataTerakhir : null);
            if (!isset($data_view['tglPenarikanRiwayatKredit'])) {
                $data_view['tglPenarikanRiwayatKredit'] = isset($slik_detail->DataPokokDebitur->tanggalUpdate) ? $slik_detail->DataPokokDebitur->tanggalUpdate : null;
            }
        } else {
            $data_view['riwayatKredit'] = null;
        }

        $data_view['tujuan_guna'] = [];
        $tujuan_guna = !empty($response_tujuan_penggunaan) ? $response_tujuan_penggunaan : new stdClass;
        if (isset($response_tujuan_penggunaan) && !empty($response_tujuan_penggunaan)) {
            foreach ($tujuan_guna as $val) {
                $data_view['tujuan_guna'][$val->DESC1] = $val->DESC2;
                $tuj[$val->DESC1] = $val->DESC1 . ' - ' . $val->DESC2;
            }
            $data_view['drop_tujuan_guna_kredit'] = $tuj;
        }

        $data_view['bidang_usaha'] = [];
        $bidang_usaha = isset($response_bidang_usaha) ? $response_bidang_usaha : new stdClass;
        if (isset($response_bidang_usaha) && !empty($response_bidang_usaha)) {
            foreach ($bidang_usaha as $val) {
                $data_view['bidang_usaha'][$val->DESC4] = $val->DESC2;
            }
        }

        $data_view['jabatan'] = [];
        $jabatan = isset($response_jabatan) ? $response_jabatan : new stdClass;
        if (isset($response_jabatan) && !empty($response_jabatan)) {
            foreach ($jabatan as $val) {
                $data_view['jabatan'][$val->DESC1] = $val->DESC2;
            }
        }

        $data_view['tahun_berjalan'] = date('Y');

        $data_view['flag_overide'] = $this->prakarsamediumRepo->getFlagOveride($request->refno);

        $x = 0;
        $pengelolaan_keuangan = isset($data_view['pengelolaan_keuangan']) ? $data_view['pengelolaan_keuangan'] : [];
        $data_view['content_data_asumsi'] = isset($data_view['pengelolaan_keuangan']) && isset($data_view['pengelolaan_keuangan']->content_data_asumsi) ? json_decode($data_view['pengelolaan_keuangan']->content_data_asumsi, FALSE) : '-';
        if (isset($data_view['content_data_asumsi']->proyeksi_cashflow)) {
            $data_view['proyeksi_cashflow'] = $data_view['content_data_asumsi']->proyeksi_cashflow;
        }

        // riwayat kredit
        $kolekKet = ['1' => 'Lancar', '2' => 'Dalam Perhatian Khusus', '3' => 'Kurang Lancar', '4' => 'Diragukan', '5' => 'Macet'];

        if (isset($data_view['pengelolaan_keuangan']->tahun_berjalan)) {
            $data_view['tahun_berjalan'] = $data_view['pengelolaan_keuangan']->tahun_berjalan;
        }

        if ((isset($data_view['pengelolaan_keuangan'])) && !empty($data_view['pengelolaan_keuangan']->content_data_polaangsuran)) {
            $data_view['det_pola_angsuran'] = json_decode($data_view['pengelolaan_keuangan']->content_data_polaangsuran);
        }

        $param_agunan = $this->prakarsamediumRepo->getDataParamAgunan();
        $data_view['param_agunan'] = array();
        foreach ($param_agunan as $res_agunan) {
            $data_view['param_agunan'][$res_agunan->param]['persen_nl'] = $res_agunan->persen_nl;
            $data_view['param_agunan'][$res_agunan->param]['persen_pnpw'] = $res_agunan->persen_pnpw;
        }

        $non_fin = $this->prakarsamediumRepo->getDatanonfinansialSme();
        foreach ($non_fin as $val) {
            $data_view['non_fin_arr']['jawaban'][$val->param_db][$val->poin] = $val->deskripsi;
            $data_view['non_fin_arr']['pertanyaan'][$val->param_db] = $val->pertanyaan;
        }

        $data_view['input_field_laba_rugi'] = array(
            'periode' => 'Periode', 'audited' => 'Audited', 'penjualan_bersih' => 'Penjualan', 'hpp' => 'Harga Pokok Penjualan', 'laba_kotor' => 'Laba Kotor', 'sewa_dan_sewa_beli' => 'Sewa dan Sewa Beli', 'biaya_penjualan_umum_adm' => 'Biaya Penjualan Umum Administrasi', 'biaya_operasional_lainnya' => 'Biaya Operasional Lainnya', 'keuntungan_operasional' => 'Laba Operasional', 'biaya_penyusutan' => 'Biaya Penyusutan', 'biaya_amortisasi' => 'Biaya Amortisasi', 'biaya_bunga' => 'Biaya Bunga', 'biaya_pribadi' => 'Biaya Pribadi', 'biaya_non_operasional_lainnya' => 'Biaya Operasional Lainnya', 'pendapatan_non_operasional_lainnya' => 'Pendapatan Non Operasional Lainnya', 'laba_sebelum_pajak' => 'Laba Sebelum Pajak', 'pajak' => 'Pajak'
            // , 'soles_general_administration' => 'Soles General Administrations'
        );

        try {
            foreach ($data_view['input_field_laba_rugi'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            $data_view['value_field']['2_sebelum']['laba_bersih'] = '';
            $data_view['value_field']['1_sebelum']['laba_bersih'] = '';
            $data_view['value_field']['berjalan']['laba_bersih'] = '';
            $data_view['value_field']['proyeksi']['laba_bersih'] = '';

            if (!empty($pengelolaan_keuangan) ) {
                if (!empty($pengelolaan_keuangan->tahun_berjalan)) {
                    $data_view['tahun_berjalan'] = $pengelolaan_keuangan->tahun_berjalan;
                }

                if (!empty($pengelolaan_keuangan->content_data_labarugi)) {
                    $data_labarugi = json_decode($pengelolaan_keuangan->content_data_labarugi, TRUE);
                    foreach ($data_labarugi as $key_year => $param_year) {
                        if ($key_year == $data_view['tahun_berjalan'] - 2) {
                            foreach ($param_year as $key => $val) {
                                if (($key != 'periode' && $key != 'audited' && $key != 'tanggal_posisi') && $val != 0) {
                                    $val = $val / 1000;
                                }
                                $data_view['value_field']['2_sebelum'][$key] = $val;
                            }
                        } elseif ($key_year == $data_view['tahun_berjalan'] - 1) {
                            foreach ($param_year as $key => $val) {
                                if (($key != 'periode' && $key != 'audited' && $key != 'tanggal_posisi') && $val != 0) {
                                    $val = $val / 1000;
                                }
                                $data_view['value_field']['1_sebelum'][$key] = $val;
                            }
                        } elseif ($key_year == $data_view['tahun_berjalan']) {
                            foreach ($param_year as $key => $val) {
                                if (($key != 'periode' && $key != 'audited' && $key != 'tanggal_posisi') && $val != 0) {
                                    $val = $val / 1000;
                                }
                                $data_view['value_field']['berjalan'][$key] = $val;
                            }
                        } elseif ($key_year == $data_view['tahun_berjalan'] + 1) {
                            foreach ($param_year as $key => $val) {
                                if (($key != 'periode' && $key != 'audited' && $key != 'tanggal_posisi') && $val != 0) {
                                    $val = $val / 1000;
                                }
                                $data_view['value_field']['proyeksi'][$key] = $val;
                            }
                        }
                    }
                }
            }

            for ($i = 1; 360 / (30 * $i) >= 1; $i++) {
                $drop_periode[30 * $i] = 30 * $i;
            }

            $data_view['drop_periode'] = $drop_periode;
            $data_view['drop_audited']['Audited'] = 'Audited';
            $data_view['drop_audited']['Unaudited'] = 'Unaudited';

            // Show Value Neraca
            $data_view['input_field_aktiva'] = ['tanah' => 'Tanah', 'bangunan' => 'Bangunan', 'mesin' => 'Mesin', 'kendaraan' => 'Kendaraan', 'peralatan_kantor' => 'Perlengkapan Kantor', 'aktiva_tetap_sebelum_penyusutan' => 'Aktiva tetap sebelum penyusutan', 'akumulasi_penyusutan' => 'Akumulasi Penyusutan', 'penempatan_perusahaan_lain' => 'Penempatan Perusahaan Lain', 'investasi_lainnya' => 'Inventasi Lainnya', 'aktiva_lainnya' => 'Aktiva Tetap Lainnya', 'good_will' => 'Good Will'];
            foreach ($data_view['input_field_aktiva'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            $data_view['input_field_aktiva_lancar'] = ['kas_bank' => 'Kas Bank', 'surat_berharga' => 'Surat Berharga', 'piutang_dagang' => 'Piutang Dagang', 'persediaan' => 'Persediaan', 'piutang_lainnya' => 'Piutang Lainnya', 'biaya_dibayar_dimuka' => 'Biaya Dibayar Di Muka', 'aktiva_lancar_lainnya' => 'Aktiva Lancar Lainnya'];
            foreach ($data_view['input_field_aktiva_lancar'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            $data_view['value_field']['2_sebelum']['aktiva_pasiv'] = 0;
            $data_view['value_field']['1_sebelum']['aktiva_pasiv'] = 0;
            $data_view['value_field']['berjalan']['aktiva_pasiv'] = 0;
            $data_view['value_field']['proyeksi']['aktiva_pasiv'] = 0;
            $data_view['value_field']['2_sebelum']['aktiva_lancar'] = 0;
            $data_view['value_field']['1_sebelum']['aktiva_lancar'] = 0;
            $data_view['value_field']['berjalan']['aktiva_lancar'] = 0;
            $data_view['value_field']['proyeksi']['aktiva_lancar'] = 0;

            if (!empty($pengelolaan_keuangan) ) {
                if (!empty($pengelolaan_keuangan->tahun_berjalan)) {
                    $data_view['tahun_berjalan'] = $pengelolaan_keuangan->tahun_berjalan;
                }
                if (!empty($pengelolaan_keuangan->content_data_neraca)) {
                    $data_neraca = json_decode($pengelolaan_keuangan->content_data_neraca, TRUE);
                    foreach ($data_neraca as $id => $param_year) {
                        if ($id == $data_view['tahun_berjalan'] - 2) {
                            foreach ($param_year as $key => $val) {
                                $data_view['value_field']['2_sebelum'][$key] = $val / 1000;
                            }
                        } elseif ($id == $data_view['tahun_berjalan'] - 1) {
                            foreach ($param_year as $key => $val) {
                                $data_view['value_field']['1_sebelum'][$key] = $val / 1000;
                            }
                        } elseif ($id == $data_view['tahun_berjalan']) {
                            foreach ($param_year as $key => $val) {
                                $data_view['value_field']['berjalan'][$key] = $val / 1000;
                            }
                        }
                    }
                }
            }

            $data_view['value_field']['2_sebelum']['total_aktiva'] = $data_view['value_field']['2_sebelum']['aktiva_lancar'] + $data_view['value_field']['2_sebelum']['aktiva_pasiv'];
            $data_view['value_field']['1_sebelum']['total_aktiva'] = $data_view['value_field']['1_sebelum']['aktiva_lancar'] + $data_view['value_field']['1_sebelum']['aktiva_pasiv'];
            $data_view['value_field']['berjalan']['total_aktiva'] = $data_view['value_field']['berjalan']['aktiva_lancar'] + $data_view['value_field']['berjalan']['aktiva_pasiv'];
            $data_view['value_field']['proyeksi']['total_aktiva'] = $data_view['value_field']['proyeksi']['aktiva_lancar'] + $data_view['value_field']['proyeksi']['aktiva_pasiv'];

            // value passiva
            $data_view['input_field_neraca'] = array(
                'hutang_bank_jangka_pendek' => 'Hutang Bank Jangka Pendek', 'hutang_dagang' => 'Hutang Dagang', 'kewajiban_yang_masih_harus_dibayar' => 'Kewajiban Masih Harus Dibayar', 'kewajiban_yang_masih_harus_dibayar_pihak_3' => 'Kewajiban Masih Dibayar Pihak 3', 'hutang_lain' => 'Hutang Lain', 'hutang_pajak' => 'Hutang Pajak', 'hutang_jangka_panjang_segera_jatuh_tempo' => 'Hutang Jangka Panjang Segera Jatuh Tempo'
            );
            $data_view['input_field_neraca_hutang_panjang'] = array(
                'pembayaran_uang_muka' => 'Pembayaran Uang Muka', 'hutang_bank_jangka_panjang' => 'Hutang Bank Jangka Panjang', 'hutang_jangka_panjang_pada_pihak_3' => 'Hutang Jangka Panjang Pihak 3', 'hutang_jangka_panjang_lain' => 'Hutang Jangka Panjang Lain'
            );
            $data_view['input_field_modal'] = array(
                'saham_istimewa' => 'Saham Istimewa', 'modal' => 'Modal', 'kelebihan_nilai_modal' => 'Kelebihan Nilai Modal', 'modal_lain' => 'Modal Lain', 'prive' => 'Prive', 'revaluasi_asset' => 'Revaluasi Asset', 'cadangan_lainnya' => 'Cadangan Lainnya', 'laba_ditahan' => 'Laba Ditahan', 'laba_tahun_berjalan' => 'Laba Tahun Berjalan'
            );

            // Show Value
            foreach ($data_view['input_field_neraca'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            foreach ($data_view['input_field_neraca_hutang_panjang'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            foreach ($data_view['input_field_modal'] as $key => $value) {
                $data_view['value_field']['2_sebelum'][$key] = '';
                $data_view['value_field']['1_sebelum'][$key] = '';
                $data_view['value_field']['berjalan'][$key] = '';
                $data_view['value_field']['proyeksi'][$key] = '';
            }

            $data_view['value_field']['2_sebelum']['passiva_lancar'] = 0;
            $data_view['value_field']['1_sebelum']['passiva_lancar'] = 0;
            $data_view['value_field']['berjalan']['passiva_lancar'] = 0;
            $data_view['value_field']['proyeksi']['passiva_lancar'] = 0;
            $data_view['value_field']['2_sebelum']['hutang_jangka_panjang'] = 0;
            $data_view['value_field']['1_sebelum']['hutang_jangka_panjang'] = 0;
            $data_view['value_field']['berjalan']['hutang_jangka_panjang'] = 0;
            $data_view['value_field']['proyeksi']['hutang_jangka_panjang'] = 0;
            $data_view['value_field']['2_sebelum']['total_modal'] = 0;
            $data_view['value_field']['1_sebelum']['total_modal'] = 0;
            $data_view['value_field']['berjalan']['total_modal'] = 0;
            $data_view['value_field']['proyeksi']['total_modal'] = 0;
            $data_view['laba'] = array();
            $data_view['total_pasiva_2_sebelum'] = "";
            $data_view['total_pasiva_1_sebelum'] = "";
            $data_view['total_pasiva_berjalan'] = "";
            $data_view['total_aktiva_2_sebelum'] = "";
            $data_view['total_aktiva_1_sebelum'] = "";
            $data_view['total_aktiva_berjalan'] = "";
            $data_view['selisih_2_sebelum'] = "";
            $data_view['selisih_1_sebelum'] = "";
            $data_view['selisih_berjalan'] = "";
            $data_view['total_hutang_2_sebelum'] = "";
            $data_view['total_hutang_1_sebelum'] = "";
            $data_view['total_hutang_berjalan'] = "";
        } catch (\Exception $e) {
            throw new InvalidRuleException("Upps, ada yang salah");

        }

        if (!empty($pengelolaan_keuangan->content_data_labarugi)) {
            $data_labarugi = json_decode($pengelolaan_keuangan->content_data_labarugi, TRUE);
            foreach ($data_labarugi as $id => $vals) {
                if (isset($vals['laba_bersih'])) {
                    $laba_bersih = floor($vals['laba_bersih'] / 1000);
                    $data_view['laba'][$id] = $laba_bersih;
                }
            }
        }

        if (!empty($pengelolaan_keuangan) ) {
            if (!empty($pengelolaan_keuangan->tahun_berjalan)) {
                $data_view['tahun_berjalan'] = $pengelolaan_keuangan->tahun_berjalan;
            }
            if (!empty($pengelolaan_keuangan->content_data_neraca)) {
                $data_neraca = json_decode($pengelolaan_keuangan->content_data_neraca, TRUE);
                foreach ($data_neraca as $id => $param_year) {
                    if ($id == $data_view['tahun_berjalan'] - 2) {
                        foreach ($param_year as $key => $val) {
                            $data_view['value_field']['2_sebelum'][$key] = floor($val / 1000);
                        }
                        $data_view['total_pasiva_2_sebelum'] = floor((isset($param_year['total_passiva']) ? $param_year['total_passiva'] / 1000 : '0'));
                        $data_view['total_aktiva_2_sebelum'] = floor((isset($param_year['total_aktiva']) ? $param_year['total_aktiva'] / 1000 : '0'));
                        $data_view['total_hutang_2_sebelum'] = floor((isset($param_year['passiva_lancar']) ? $param_year['passiva_lancar'] / 1000 : '0') + (float)(isset($param_year['hutang_jangka_panjang']) ? $param_year['hutang_jangka_panjang'] / 1000 : '0'));
                        // $data_view['selisih_2_sebelum'] = $data_view['total_aktiva_2_sebelum'] - $data_view['total_pasiva_2_sebelum'];
                    } elseif ($id == $data_view['tahun_berjalan'] - 1) {
                        foreach ($param_year as $key => $val) {
                            $data_view['value_field']['1_sebelum'][$key] = floor($val / 1000);
                        }
                        $data_view['total_pasiva_1_sebelum'] = floor(isset($param_year['total_passiva']) ? $param_year['total_passiva'] / 1000 : '0');
                        $data_view['total_aktiva_1_sebelum'] = floor(isset($param_year['total_aktiva']) ? $param_year['total_aktiva'] / 1000 : '0');
                        $data_view['total_hutang_1_sebelum'] = floor((isset($param_year['passiva_lancar']) ? $param_year['passiva_lancar'] / 1000 : '0') + (float)(isset($param_year['hutang_jangka_panjang']) ? $param_year['hutang_jangka_panjang'] / 1000 : '0'));
                    } elseif ($id == $data_view['tahun_berjalan']) {
                        foreach ($param_year as $key => $val) {
                            $data_view['value_field']['berjalan'][$key] = floor($val / 1000);
                        }
                        $data_view['total_pasiva_berjalan'] = floor(isset($param_year['total_passiva']) ? $param_year['total_passiva'] / 1000 : '0');
                        $data_view['total_aktiva_berjalan'] = floor(isset($param_year['total_aktiva']) ? $param_year['total_aktiva'] / 1000 : '0');
                        $data_view['total_hutang_berjalan'] = floor((isset($param_year['passiva_lancar']) ? $param_year['passiva_lancar'] / 1000 : '0') + (float)(isset($param_year['hutang_jangka_panjang']) ? $param_year['hutang_jangka_panjang'] / 1000 : '0'));
                    }
                }
            }
        }

        $data_view['value_field']['2_sebelum']['total_passiva'] = $data_view['value_field']['2_sebelum']['passiva_lancar'] + $data_view['value_field']['2_sebelum']['hutang_jangka_panjang'] + $data_view['value_field']['2_sebelum']['total_modal'];
        $data_view['value_field']['1_sebelum']['total_passiva'] = $data_view['value_field']['1_sebelum']['passiva_lancar'] + $data_view['value_field']['1_sebelum']['hutang_jangka_panjang'] + $data_view['value_field']['1_sebelum']['total_modal'];
        $data_view['value_field']['berjalan']['total_passiva'] = $data_view['value_field']['berjalan']['passiva_lancar'] + $data_view['value_field']['berjalan']['hutang_jangka_panjang'] + $data_view['value_field']['berjalan']['total_modal'];
        $data_view['value_field']['proyeksi']['total_passiva'] = $data_view['value_field']['proyeksi']['passiva_lancar'] + $data_view['value_field']['proyeksi']['hutang_jangka_panjang'] + $data_view['value_field']['proyeksi']['total_modal'];

        //value analisa
        $data_view['input_field_ratio_keuangan_likuiditas'] = array(
            'current_ratio' => 'Current Ratio', 'quick_ratio' => 'Quick Ratio', 'net_working_capital' => 'Net Working Capital'
        );
        $data_view['input_field_ratio_keuangan_solvabilitas'] = array(
            'debt_equity_ratio' => 'Debt Equity Ratio', 'debt_to_total_asset' => 'Debt to Total Asset', 'ebit_interest' => 'EBIT/Interets', 'ebitda_interest' => 'EBITDA/(Interets + Pokok)'
        );
        $data_view['input_field_ratio_keuangan_provitabilitas'] = array(
            'gross_provit_margin' => 'Gross Provit Margin', 'net_provit_margin' => 'Net Provit Margin', 'return_on_equity' => 'Return On Equity', 'return_on_asset' => 'Return On Asset', 'interest_coverage_ratio' => 'Interest Coverage Ratio'
        );
        $data_view['input_field_ratio_keuangan_aktivitas'] = array(
            'days_of_receiveable' => 'Days Of Receiveable (DOR)', 'days_of_inventory' => 'Days Of Inventory (DOI)', 'days_of_payable' => 'Days Of Payable (DOP)', 'wcto' => 'WCTO'
        );
        foreach ($data_view['input_field_ratio_keuangan_likuiditas'] as $key => $value) {
            $data_view['value_field']['2_sebelum'][$key] = '';
            $data_view['value_field']['1_sebelum'][$key] = '';
            $data_view['value_field']['berjalan'][$key] = '';
            $data_view['value_field']['proyeksi'][$key] = '';
        }

        // Show Value
        foreach ($data_view['input_field_ratio_keuangan_solvabilitas'] as $key => $value) {
            $data_view['value_field']['2_sebelum'][$key] = '';
            $data_view['value_field']['1_sebelum'][$key] = '';
            $data_view['value_field']['berjalan'][$key] = '';
            $data_view['value_field']['proyeksi'][$key] = '';
        }

        // Show Value
        foreach ($data_view['input_field_ratio_keuangan_provitabilitas'] as $key => $value) {
            $data_view['value_field']['2_sebelum'][$key] = '';
            $data_view['value_field']['1_sebelum'][$key] = '';
            $data_view['value_field']['berjalan'][$key] = '';
            $data_view['value_field']['proyeksi'][$key] = '';
        }

        // Show Value
        foreach ($data_view['input_field_ratio_keuangan_aktivitas'] as $key => $value) {
            $data_view['value_field']['2_sebelum'][$key] = '';
            $data_view['value_field']['1_sebelum'][$key] = '';
            $data_view['value_field']['berjalan'][$key] = '';
            $data_view['value_field']['proyeksi'][$key] = '';
        }

        if (!empty($pengelolaan_keuangan) ) {
            if (!empty($pengelolaan_keuangan->tahun_berjalan)) {
                $data_view['tahun_berjalan'] = $pengelolaan_keuangan->tahun_berjalan;
            }
            if (!empty($pengelolaan_keuangan->content_ratio_keuangan)) {
                $data_view['data_ratio_keuangan'] = json_decode($pengelolaan_keuangan->content_ratio_keuangan, TRUE);
            }
            if (!empty($pengelolaan_keuangan->content_data_labarugi) && !empty($pengelolaan_keuangan->content_data_neraca)) {
                $tahun_berjalan = $data_view['tahun_berjalan'];
                $year2bfr = $tahun_berjalan - 2;
                $year1bfr = $tahun_berjalan - 1;
                $year1aft = $tahun_berjalan + 1;
                $data_laba_rugi = json_decode($pengelolaan_keuangan->content_data_labarugi, TRUE);
                $data_neraca = json_decode($pengelolaan_keuangan->content_data_neraca, TRUE);
                $arr_year = array($year2bfr => '2_sebelum', $year1bfr => '1_sebelum', $tahun_berjalan => 'berjalan');

                foreach ($arr_year as $key_year => $val) {
                    if (isset($data_laba_rugi[$key_year]) && isset($data_neraca[$key_year])) {
                        $tgl = explode('-', $data_laba_rugi[$key_year]['tanggal_posisi']);
                        $bulan = intval($tgl[1]);
                        $current_ratio = 0;
                        $quick_ratio = 0;
                        if ($data_neraca[$key_year]['aktiva_lancar'] != 0 && $data_neraca[$key_year]['passiva_lancar'] != 0) {
                            $current_ratio = ($data_neraca[$key_year]['aktiva_lancar'] / $data_neraca[$key_year]['passiva_lancar']) * 100;
                        }
                        if (($data_neraca[$key_year]['aktiva_lancar'] - $data_neraca[$key_year]['persediaan']) != 0 && $data_neraca[$key_year]['passiva_lancar'] != 0) {
                            $quick_ratio = (($data_neraca[$key_year]['aktiva_lancar'] - $data_neraca[$key_year]['persediaan']) / $data_neraca[$key_year]['passiva_lancar']) * 100;
                        }
                        $data_view['value_field'][$val]['current_ratio'] = round($current_ratio, 0);
                        $data_view['value_field'][$val]['quick_ratio'] = round($quick_ratio, 0);
                        $data_view['value_field'][$val]['net_working_capital'] = round($data_neraca[$key_year]['aktiva_lancar'] - $data_neraca[$key_year]['passiva_lancar'], 0);

                        $debt_equity_ratio = 0;
                        $debt_to_total_asset = 0;
                        $ebit_interest = 0;
                        $ebitda_interest = 0;
                        if ($data_neraca[$key_year]['total_hutang'] != 0 && $data_neraca[$key_year]['total_modal'] != 0) {
                            $debt_equity_ratio = ($data_neraca[$key_year]['total_hutang'] / $data_neraca[$key_year]['total_modal']) * 100;
                        }
                        if ($data_neraca[$key_year]['total_hutang'] != 0 && $data_neraca[$key_year]['total_aktiva'] != 0) {
                            $debt_to_total_asset = ($data_neraca[$key_year]['total_hutang'] / $data_neraca[$key_year]['total_aktiva']) * 100;
                        }
                        if ($data_laba_rugi[$key_year]['laba_sebelum_pajak'] != 0 && $data_laba_rugi[$key_year]['biaya_bunga'] != 0) {
                            $ebit_interest = $data_laba_rugi[$key_year]['laba_sebelum_pajak'] / $data_laba_rugi[$key_year]['biaya_bunga'];
                            $ebit_interest = round($ebit_interest, 1);
                        }
                        if ($data_laba_rugi[$key_year]['laba_sebelum_pajak'] != 0 && isset($bulan) && $data_laba_rugi[$key_year]['angsuran_pokok_1_tahun'] + $data_laba_rugi[$key_year]['angsuran_bunga_1_tahun'] != 0) {
                            $ebitda = $data_laba_rugi[$key_year]['laba_sebelum_pajak'] + $data_laba_rugi[$key_year]['biaya_bunga'] + $data_laba_rugi[$key_year]['biaya_amortisasi'] + $data_laba_rugi[$key_year]['biaya_penyusutan'];
                            $ebitda_interest = (($ebitda * 12) / ($bulan)) / ($data_laba_rugi[$key_year]['angsuran_bunga_1_tahun'] + $data_laba_rugi[$key_year]['angsuran_pokok_1_tahun']);
                            $ebitda_interest = round($ebitda_interest, 1);
                        }
                        $data_view['value_field'][$val]['debt_equity_ratio'] = round($debt_equity_ratio, 1);
                        $data_view['value_field'][$val]['debt_to_total_asset'] = round($debt_to_total_asset, 1);
                        $data_view['value_field'][$val]['ebit_interest'] = $ebit_interest;
                        $data_view['value_field'][$val]['ebitda_interest'] = $ebitda_interest;

                        $gross_provit_margin = 0;
                        $net_provit_margin = 0;
                        $return_on_equity = 0;
                        $return_on_asset = 0;
                        $interest_coverage_ratio = 0;
                        $ratio_sales = 0;
                        if ($data_laba_rugi[$key_year]['laba_kotor'] != 0 && $data_laba_rugi[$key_year]['penjualan_bersih'] != 0) {
                            $gross_provit_margin = ($data_laba_rugi[$key_year]['laba_kotor'] / $data_laba_rugi[$key_year]['penjualan_bersih']) * 100;
                        }
                        if ($data_laba_rugi[$key_year]['laba_bersih'] != 0 && $data_laba_rugi[$key_year]['penjualan_bersih'] != 0) {
                            $net_provit_margin = ($data_laba_rugi[$key_year]['laba_bersih'] / $data_laba_rugi[$key_year]['penjualan_bersih']) * 100;
                        }
                        if ($data_laba_rugi[$key_year]['laba_bersih'] != 0 && $data_neraca[$key_year]['total_modal'] != 0 && $data_laba_rugi[$key_year]['periode'] != 0) {
                            $return_on_equity = ($data_laba_rugi[$key_year]['laba_bersih'] / $data_neraca[$key_year]['total_modal']) * 100 * (360 / $data_laba_rugi[$key_year]['periode']);
                        }
                        if ($data_laba_rugi[$key_year]['laba_bersih'] != 0 && $data_neraca[$key_year]['total_aktiva'] != 0 && $data_laba_rugi[$key_year]['periode'] != 0) {
                            $return_on_asset = ($data_laba_rugi[$key_year]['laba_bersih'] / $data_neraca[$key_year]['total_aktiva']) * 100 * (360 / $data_laba_rugi[$key_year]['periode']);
                        }
                        if ($data_laba_rugi[$key_year]['keuntungan_operasional'] != 0 && $data_laba_rugi[$key_year]['biaya_bunga'] != 0) {
                            $interest_coverage_ratio = ($data_laba_rugi[$key_year]['keuntungan_operasional'] / $data_laba_rugi[$key_year]['biaya_bunga']) * 100;
                        }
                        if ($data_laba_rugi[$key_year]['penjualan_bersih'] != 0 && isset($data_laba_rugi[$key_year - 1]['penjualan_bersih']) && $data_laba_rugi[$key_year - 1]['penjualan_bersih'] != 0 && $data_laba_rugi[$key_year - 1]['penjualan_bersih'] != 0 && $bulan != 0) {

                            $ratio_sales = ($data_laba_rugi[$key_year]['penjualan_bersih'] / $data_laba_rugi[$key_year - 1]['penjualan_bersih']) * (12 / $bulan) * 100;
                        }
                        $data_view['value_field'][$val]['gross_provit_margin'] = round($gross_provit_margin, 1);
                        $data_view['value_field'][$val]['net_provit_margin'] = round($net_provit_margin, 1);
                        $data_view['value_field'][$val]['return_on_equity'] = round($return_on_equity, 1);
                        $data_view['value_field'][$val]['return_on_asset'] = round($return_on_asset, 1);
                        $data_view['value_field'][$val]['interest_coverage_ratio'] = round($interest_coverage_ratio, 1);
                        $data_view['value_field'][$val]['ratio_sales'] = round($ratio_sales, 1);

                        $days_of_receiveable = 0;
                        $days_of_inventory = 0;
                        $days_of_payable = 0;
                        $wcto = 0;

                        if ($data_neraca[$key_year]['piutang_dagang'] != 0 && $data_laba_rugi[$key_year]['penjualan_bersih'] != 0) {
                            $days_of_receiveable = ($data_neraca[$key_year]['piutang_dagang'] / $data_laba_rugi[$key_year]['penjualan_bersih']) * $data_laba_rugi[$key_year]['periode'];
                        }
                        if ($data_neraca[$key_year]['persediaan'] != 0 && $data_laba_rugi[$key_year]['hpp'] != 0) {
                            $days_of_inventory = ($data_neraca[$key_year]['persediaan'] / $data_laba_rugi[$key_year]['hpp']) * $data_laba_rugi[$key_year]['periode'];
                        }
                        if ($data_neraca[$key_year]['hutang_dagang'] != 0 && $data_laba_rugi[$key_year]['hpp'] != 0) {
                            $days_of_payable = ($data_neraca[$key_year]['hutang_dagang'] / $data_laba_rugi[$key_year]['hpp']) * $data_laba_rugi[$key_year]['periode'];
                        }
                        $wcto = round($days_of_receiveable, 0) + round($days_of_inventory, 0);
                        $data_view['value_field'][$val]['days_of_receiveable'] = round($days_of_receiveable, 0);
                        $data_view['value_field'][$val]['days_of_inventory'] = round($days_of_inventory, 0);
                        $data_view['value_field'][$val]['days_of_payable'] = round($days_of_payable, 0);
                        $data_view['value_field'][$val]['wcto'] = $wcto;
                    }
                }
            }
        }

        //parameter drop down
        $data_view['drop_perjanjian_kredit'] = array("1" => "Notaris", "2" => "Dibawah Tangan", "3" => "Dibawah Tangan yang di-waar marking", "4" => "Dibawah Tangan Legalisir");
        $data_view['drop_bentuk_kredit'] = array("1" => "Max CO Tetap", "2" => "Max CO Menurun", "3" => "Angsuran Tetap");

        $data_approval = isset($get_prakarsa->approval) ? $get_prakarsa->approval : [];
        if (!empty($data_approval)) {
            $qrcodePath = env('PATH_NFS_PRAKARSA') . '/' . $get_prakarsa->branch . '/' . $get_prakarsa->refno . '/cetakan/qrcode/';
            if(!is_dir($qrcodePath)) {
                mkdir($qrcodePath, 0775, true);
            }
            foreach ($data_approval as $val_approval) :
                if (isset($val_approval->jenis_user) && in_array($val_approval->jenis_user, ["BISNIS", "RISK"])) {
                    $data_qrcode = [
                        'message' => $val_approval->pn . ' - ' . $val_approval->nama . ' : Pemutus ' . $val_approval->jenis_user
                    ];

                    $qr_code = $this->generateQrcode($data_qrcode);

                    $val_approval->qrcode = $qr_code;
                }
            endforeach;
        }

        // set new qrcode item
        $get_prakarsa->approval = $data_approval;

        $data_view2 = $data_view;

        Queue::pushOn(env('QUEUE_NAME_RITEL'), new generateMABMenengahJob($data_view2));
        
        $path_file = !empty($get_prakarsa->path_file) ? json_decode($get_prakarsa->path_file) : new stdClass;
        $path_folder = !empty($get_prakarsa->path_folder) ? $get_prakarsa->path_folder : NULL;
        $documentFileName = "memorandum_analisis_bisnis_dan_putusan_kredit_bisnis_uppersmall_medium.pdf";
        
        if (empty($path_folder)) {
            throw new DataNotFoundException("Path folder tidak ditemukan");
        }

        if (empty($path_file)) {
            throw new DataNotFoundException("Path file tidak ditemukan");
        }
        
        $hash_link = Str::random(10);
        if (!is_dir($path_folder)) {
            mkdir($path_folder, 0775, true);
        }
        $symlink_url = 'ln -sf ' . $path_folder . ' ' . base_path('public'). '/securelink/' . $hash_link;
        exec($symlink_url);
        $url_file = env('BRISPOT_MCS_URL') . '/prakarsa/securelink/' . $hash_link;
        
        $new_url = '';
        $keterangan = '';
        $flag = '';
        if (!empty($path_file->cetakan) && in_array($documentFileName, $path_file->cetakan)) {
            
            $flag = "1";
            $keterangan = "Berhasil generate dokumen MAB";
            $new_url = $url_file . '/cetakan/' .'memorandum_analisis_bisnis_dan_putusan_kredit_bisnis_uppersmall_medium.pdf';

        } else {
            $flag = "0";
            $keterangan = "Mohon menunggu proses generate dokumen MAB belum selesai";
            $new_url = "Link download belum tersedia";
        }

        $responseData = new stdClass();
        $responseData->flag = $flag;
        $responseData->keterangan = $keterangan;
        $responseData->url_file = $new_url;

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Berhasil';
        $this->output->responseData = $responseData;

        return response()->json($this->output);
    }
}