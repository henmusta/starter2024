<?php

class Controller extends BaseController
{

public function prescreeningRitel2(Request $request, RestClient $client, MonolithicClient $mono_client)
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
            throw new ParameterException("Parameter nama debitur tidak boleh kosong");
        }
        $nama = trim($request->nama_debitur);

        if (!isset($request->nik) || empty($request->nik)) {
            throw new ParameterException("Parameter NIK tidak boleh kosong");
        }
        $nik = trim($request->nik);

        if (!isset($request->kode_sikp) || empty($request->kode_sikp)) {
            throw new ParameterException("Kode SIKP tidak boleh kosong");
        }
        $kode_sikp = trim($request->kode_sikp);

        if (!isset($request->flag_bkpm) || (trim($request->flag_bkpm) == '') || (!in_array(trim($request->flag_bkpm), array('0', '1')))) {
            throw new ParameterException("Prescreening gagal. kode BPKM kosong/salah");
        }
        $flag_bkpm = trim($request->flag_bkpm);

        $objsid = new stdClass;
        $objdhn = new stdClass;
        $objsicd = new stdClass;

        $arrgender = array('l' => '1', 'p' => '2');
        $msgcomplete = "";
        $company = "";
        $warningmsg = "";
        $screenPs = array();
        $screenBkpm = array();
        $screenSac = array();

        $dataPrakarsa = $this->prakarsamediumRepo->getCiflasByRefno($refno, $branch, $pn);
        if (empty($dataPrakarsa)) {
            throw new DataNotFoundException("Data prakarsa tidak ditemukan");
        }

        /** Start early return based on status prakarsa */
            if ($dataPrakarsa->status != '1') {
                $responseData = new stdClass;
                $this->output->responseCode = '00';
                $this->output->responseDesc = 'Anda sudah tidak dibolehkan update data(prakarsa sudah memiliki hasil CRS), silahkan lanjut ke tahap berikutnya.';
                $this->output->responseData = $responseData;
                return response()->json($this->output);
            }
        /** End early return based on status prakrasa */

        /** Start Prep Data Pasar Sasaran */
        $contentDataPribadi = json_decode($dataPrakarsa->content_datapribadi);

        if (empty($contentDataPribadi->bidang_usaha_ojk)) {
            throw new InvalidRuleException('Bidang Usaha OJK kosong, periksa isian data pribadi.');
        }

        if (empty($contentDataPribadi->bidang_usaha)) {
            throw new InvalidRuleException('Bidang Usaha SID kosong, periksa isian data pribadi.');
        }

        $microserviceMaster = trim(env('BRISPOT_MASTER_URL'));
        $requestBody = array(
            "sekon_lbut" => trim($contentDataPribadi->bidang_usaha_ojk),
            "sekon_sid" => trim($contentDataPribadi->bidang_usaha),
            "kode_sikp" => $kode_sikp
        );

        $RespDataSektorEkonomi = $client->call($requestBody, $microserviceMaster, '/v1/inquirySekonSmeProvinsi');
        if (isset($RespDataSektorEkonomi->responseCode) && $RespDataSektorEkonomi->responseCode != "00") {
            throw new DataNotFoundException($RespDataSektorEkonomi->responseDesc);
        }
        $dataSektorEkonomiProvinsi = $RespDataSektorEkonomi->responseData;

        $kodeWarnaPasarSasaran = trim($dataSektorEkonomiProvinsi->warna);
        switch ($kodeWarnaPasarSasaran) {
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

        $kodePasarSasaran = $kodeWarnaPasarSasaran;
        /** Predp Data Pasar Sasaran */

        /** Start Pred Data Prescreening SAC (Score Acceptance Criteria) */
            $dataSac = $request->sac;
            $dataSac['kode_sac'] = trim($kodePasarSasaran);
            $dataSac['warna'] = $warna;

            $ReqBodyDataSac = array(
                "pn" => $pn,
                "refno" => $refno,
                "tp_produk" => $tp_produk
            );

            $RespDataSac =  $client->call($ReqBodyDataSac, $microserviceMaster, '/v1/inquiryListPrescreeningSac');
            if (isset($RespDataSac->responseCode) && $RespDataSac->responseCode != "00") {
                throw new DataNotFoundException($RespDataSac->responseDesc);
            }

            $getPertanyaanSAC = $RespDataSac->responseData;
            if (count($getPertanyaanSAC) == 0) {
                throw new DataNotFoundException("Data tidak ditemukan");
            }

            $arr_umum = [];
            $arr_khusus = [];  // Spesifik SAWIT + PARIWISATA
            foreach ($getPertanyaanSAC as $item) {
                if ($item->tipe == "umum") {
                    $list_pertanyaan = isset($dataSac['pertanyaan_umum']) ? $dataSac['pertanyaan_umum'] : [];

                    $value_SAC = [
                        "pertanyaan" => $item->pertanyaan,
                        "value"      => in_array($item->pertanyaan, $list_pertanyaan) ? 1 : 0 // compare data kiriman mobile dengan data SAC DB
                    ];
                    array_push($arr_umum, $value_SAC);
                } elseif ($item->tipe == "khusus" || $item->tipe == "spesifik") {
                    $list_pertanyaan = isset($dataSac['pertanyaan_khusus']) ? $dataSac['pertanyaan_khusus'] : [];
                    $value_SAC = [
                        "pertanyaan" => $item->pertanyaan,
                        "value"      => in_array($item->pertanyaan, $list_pertanyaan) ? 1 : 0 // compare data kiriman mobile dengan data SAC DB
                    ];
                    array_push($arr_khusus, $value_SAC);
                }
            }
        /** End Prescreening SAC */

        $dateActivity = date('Y-m-d H:i:s');
        $ref_flag_override = '0';

        $keterangan_tolak = "Prakarsa tidak dapat dilanjutkan.";
        $keterangan_lolos = "Lolos pre-screening. Prakarsa dapat dilanjutkan.";
        $keterangan_override = "Lolos pre-screening. Prakarsa dapat dilanjutkan dengan mekanisme override.";

        $tolak_ps = '';
        $tolak_bkpm = '';
        $tolak_dhn = '';
        $tolak_slik = '';
        $tolak_sicd = '';
        $tolak_kemendagri = '';
        $override = '';

        $objPasarSasaranDeb = new stdClass;
        $objPasarSasaranDeb->nama_debitur = $nama;
        $objPasarSasaranDeb->result = "0";
        $objPasarSasaranDeb->kode_warna = $kodePasarSasaran;
        $objPasarSasaranDeb->warna = $warna;
        $objPasarSasaranDeb->flag_override = "N";

        $kategoriNasabah = trim($dataPrakarsa->kategori_nasabah);
        $perpanjangan = "1";
        $deplesi = "4";

        $cekHasil = '1';

        /** Start Prescreening Pasar Sasaran */
            if ($kodePasarSasaran == '4' && in_array($kategoriNasabah, array($perpanjangan, $deplesi))) {
                /** KHUSUS RENEWAL (PERPANJANGAN & DEPLESI TIDAK BOLEH OVERRIDE DAN LOLOS) */
                $ref_flag_override = '0';
                $cekHasil = '1';

                $objPasarSasaranDeb->result = '1';
                $objPasarSasaranDeb->flag_override = "N";
            } elseif (($kodePasarSasaran == '4') && ($tp_produk == '47')) { // 0 = baru, 2 = suplesi, 3 = restruk
                $ref_flag_override = '0';
                $cekHasil = '0';
                $tolak_ps = " -Gagal pada Pasar Sasaran";

                $objPasarSasaranDeb->result = '0';
                $objPasarSasaranDeb->flag_override = "N";
            } elseif (($kodePasarSasaran == '4') && ($tp_produk == '48')) {
                $ref_flag_override = '1';
                $cekHasil = '2';
                $override .= " -Pasar sasaran masuk kategori merah";

                $objPasarSasaranDeb->result = '1';
                $objPasarSasaranDeb->flag_override = "Y";
            }

            $param_override = new stdClass();
            $param_override->refno = $refno;
            $param_override->id_override = "1";
            $param_override->flag_override = $ref_flag_override;
            $param_override->ket_override = "Prescreening Pasar Sasaran '.$objPasarSasaranDeb->warna.'";

            $request_override = new stdClass();
            $request_override->requestMethod = "insertOverrideSME";
            $request_override->requestUser = $pn;
            $request_override->requestData = $param_override;

            $response_override = $mono_client->call($request_override)->getObjectResponse();
            if (empty($response_override) || (isset($response_override->responseCode) && $response_override->responseCode <> "00")) {
                throw new InvalidRuleException(isset($response_override->responseDesc) ? $response_override->responseDesc : "Gagal insert data override");
            }

            $objpspers = new stdClass;
            $objpspers->debitur = $objPasarSasaranDeb;
            array_push($screenPs, $objpspers);

            $array_ps = array(
                'nama' => $nama,
                'refno' => $refno,
                'nik' => $nik,
                'branch' => $branch,
                'userid_ao' => $pn,
                'data' => json_encode($objPasarSasaranDeb),
                'updated' => $dateActivity
            );
            $this->prakarsamediumRepo->updatePrescreenPs($array_ps);
        /** End Prescreening Pasar Sasaran */

        /** Start Prescreening Scoring Acceptance Criteria (SAC) / KRD
             * Kode SAC = Kode Warna
             * 1 = Hijau 
             * 2 = Hijau Muda 
             * 3 = Kuning 
             * 4 = Merah
             */
            $kode_sac = $kodeWarnaPasarSasaran;
            $checkedSac = array();

            $dataSacMobile = array_merge($arr_umum, $arr_khusus);
            foreach ($dataSacMobile as $item) {
                if ($item['value'] == "1") {
                    array_push($checkedSac, $item);
                }
            }
            $dataSacHost = count($getPertanyaanSAC);

            $objSacDebitur = new stdClass;
            $objSacDebitur->nama_debitur = $nama;
            $objSacDebitur->result = "1";
            $objSacDebitur->kode_warna = $kode_sac;
            $objSacDebitur->warna = $dataSac['warna'];
            $objSacDebitur->pertanyaan_umum = $arr_umum;
            $objSacDebitur->pertanyaan_khusus = $arr_khusus;

            if (count($checkedSac) < $dataSacHost) {
                $ref_flag_override = '1';
                $cekHasil = '2';
                $override .= " -Terdapat ketidaksesuaian pada SAC";
            
                $objSacDebitur->flag_override = "Y";
            } else {
                $objSacDebitur->flag_override = "N";
            }

            $param_override = new stdClass();
            $param_override->refno = $refno;
            $param_override->id_override = "1";
            $param_override->flag_override = $ref_flag_override;
            $param_override->ket_override = "Prescreening SAC " . $objSacDebitur->warna;

            $request_override = new stdClass();
            $request_override->requestMethod = "insertOverrideSME";
            $request_override->requestUser = $pn;
            $request_override->requestData = $param_override;

            $response_override = $mono_client->call($request_override)->getObjectResponse();
            if (empty($response_override) || (isset($response_override->responseCode) && $response_override->responseCode <> "00")) {
                throw new InvalidRuleException(isset($response_override->responseDesc) ? $response_override->responseDesc : "Gagal insert data override");
            }

            $objScreen = new stdClass;
            $objScreen->sac = $objSacDebitur;

            $objsacpers = new stdClass;
            $objsacpers->debitur = $objSacDebitur;
            array_push($screenSac, $objsacpers);

            $arrdata = array(
                'upddate' => $dateActivity,
                'override' => $objSacDebitur->flag_override
            );

            /** Do Update mst_prakarsa */
            $this->prakarsamediumRepo->updatePrakarsa($refno, $arrdata);

            $array_sac = array(
                'nama' => $nama,
                'refno' => $refno,
                'nik' => $nik,
                'branch' => $branch,
                'userid_ao' => $pn,
                'data' => json_encode($objSacDebitur),
                'updated' => $dateActivity
            );

            /** Do update prescreen_sac */
            $this->prakarsamediumRepo->updatePrescreenSac($array_sac);

        /** End Prescreening Scoring Acceptance Criteria (SAC) / KRD */

        /** Start Prescreening BKPM */
            if ($flag_bkpm == 1) {
                $objBkpmDebitur = new stdClass;
                $objBkpmDebitur->nama_debitur = $nama;
                $objBkpmDebitur->result = $flag_bkpm;
                $objbkpm = $objBkpmDebitur;
            } else {
                $objBkpmDebitur = new stdClass;
                $objBkpmDebitur->nama_debitur = $nama;
                $objBkpmDebitur->result = $flag_bkpm;
                $tolak_bkpm = " -Gagal pada BKPM";
                $cekHasil = '0';
                $objbkpm = $objBkpmDebitur;
            }

            $objbkpmpers = new stdClass;
            $objbkpmpers->debitur = $objBkpmDebitur;
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

            /** Do update prescreen_bkpm */
            $this->prakarsamediumRepo->updatePrescreenBkpm($array_bkpm);

        /** End Prescreening BKPM */

        if ($objSacDebitur->flag_override == "Y" || $objPasarSasaranDeb->flag_override == "Y") {
            $array_override_prakarsa = array(
                'override' => 'Y'
            );
            $this->prakarsamediumRepo->updatePrakarsa($refno, $array_override_prakarsa);
        }

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

        $objsikp = new stdClass();
        $objsikp->debitur = null;

        foreach ($person as $id => $val) {
            $objScreen = new stdClass;
            if (!isset($val['company']) || !in_array(trim($val['company']), array('0', '1'))) {
                throw new InvalidRuleException("Prescreening gagal. Jenis debitur salah");
            }
            $company = trim($val['company']);
            $custtype = trim($val['custtype']);

            if (!isset($val['nik']) || trim($val['nik']) == '') {
                throw new InvalidRuleException($company == '1' ? "NPWP badan usaha kosong. " : "NIK debitur kosong. ");
            }
            $nik = trim($val['nik']);
            if (trim($val['company']) == '1') {
                $sikp = $nik;
            } else {
                if ($sikp == '') {
                    if (isset($val['sikp_pic']) && trim($val['sikp_pic']) != '') {
                        $sikp = $nik;
                    }
                }
            }

            if (!isset($val['nama']) || trim($val['nama']) == '') {
                throw new ParameterException("Prescreening gagal. nama debitur kosong/salah");
            }
            $nama = trim($val['nama']);

            if ($company == '0') {
                /** Perorangan */
                if (!isset($val['alias']) || trim($val['alias']) == '') {
                    throw new ParameterException("Prescreening gagal. nama alias debitur kosong/salah");
                }
                $alias = trim($val['alias']);

                if (!isset($val['tgllahir']) || trim($val['tgllahir']) == '') {
                    throw new ParameterException("Prescreening gagal. tanggal lahir debitur kosong/salah.");
                }

                if ($val['tgllahir'] != date('Y-m-d', strtotime($val['tgllahir']))) {
                    throw new InvalidRuleException("Prescreening gagal. format tanggal lahir salah. ");
                }
                $tgllahir = trim($val['tgllahir']);

                if (!isset($val['gender']) || trim($val['gender']) == '' || in_array($val['gender'], ['l', 'p']) != true) {
                    throw new ParameterException("Prescreening gagal. gender debitur kosong/salah");
                }
                $gender = trim($val['gender']);
            } else {
                /** Badan Usaha */
                if ($val['gender'] == 'l') {
                    $val['gender'] = '1';
                } elseif ($val['gender'] == 'p') {
                    $val['gender'] = '2';
                } else {
                    $val['gender'] = '0';
                }
                $tgllahir = trim($val['tgllahir']);
                $gender = $val['gender'];
                $arrgender[$gender] = $val['gender'];
                $alias = trim($val['nama']);
            }

            if (!isset($val['tempatlahir']) || trim($val['tempatlahir']) == '') {
                throw new InvalidRuleException($company == '1' ? "tempat usaha didirikan kosong. " : "tempat lahir debitur kosong. ");
            }
            $tempatlahir = trim($val['tempatlahir']);

            if (!isset($val['company']) || !in_array(trim($val['company']), array('0', '1'))) {
                throw new InvalidRuleException("Prescreening gagal. Jenis debitur salah");
            }

            /** 
             * Jenis Cust Type
             * A = Peminjam, S = Pasangan Peminjam, J = Penjamin, 
             * C = Perusahaan, Y = Pemilik, Saham diatas 25%, N = Pemilik Saham dibawah 25%, 
             * T = Pengurus Tanpa Kepemilikan
             */
            $custtype = trim($val['custtype']);

            $data_contentPrescreen = array(
                'content_dataprescreening' => '',
                'upddate' => $dateActivity
            );
            $this->prakarsamediumRepo->updatePrakarsa($refno, $data_contentPrescreen);

            $dataUpd2 = array(
                'custtype' => $custtype
            );
            $this->prakarsamediumRepo->updateCusttype($refno, $nik, $dataUpd2);

            $cekpengajuandebitur = $this->prakarsamediumRepo->cekPengajuanPrescreeningSme($branch, $pn, $refno, $nik, $nama);
            if (count($cekpengajuandebitur) == 0) {
                $cekHasil = '0'; // prescreening belum selesai (SLIK, DHN, SICD, Kemen)

                /** Start Unkown Features : tidak di migrasikan ke microservices */
                    // $CI->service_model->gen_log_image_dokumentasi($refno, $nik);
                /** End Unkown Features : tidak di migrasikan ke microservices */

                $flagawaldebitur = ($tp_produk == '12' ? '1' : '0');
                $flagawaldebSIKP = ($val['company'] == '1' ? '0' : '1');

                if ($val['company'] == '0') { // perorangan
                    if (isset($val['sikp_pic']) && trim($val['sikp_pic']) != '') {
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

                $slik_cbasid = env('BYPASS_PRESCREENING_SLIK') == TRUE ? "SME" . date('ymd') . rand(10000, 99999) : NULL;
                $arrdata = array(
                    'pernr' => $pn,
                    'branch' => $branch,
                    'tp_produk' => $tp_produk,
                    'refno' => $refno,
                    'bibr' => $bibr,
                    'custtype' => $custtype,
                    'nik' => $nik,
                    'nama' => $nama,
                    'alias' => !empty($alias) ? $alias : $nama,
                    'gender' => $arrgender[$gender],
                    'tgl_lahir' => $tgllahir,
                    'tempat_lahir' => $tempatlahir,
                    'company' => $company,
                    'kemendagri' => $flagawaldebitur,
                    'dhn' => $flagawaldebitur,
                    'sikp' => $flagawaldebSIKP,
                    'sicd' => $flagawaldebitur,
                    'ps' => '1',
                    'bkpm' => $flag_bkpm,
                    'slik' => (env('BYPASS_PRESCREENING_SLIK') == TRUE ? '1' : $flagawaldebitur),
                    'add_slik' => (env('BYPASS_PRESCREENING_SLIK') == TRUE ? '1' : $flagawaldebitur),
                    'slik_cbasid' => $slik_cbasid,
                    'onprocess' => '0',
                    'done' => $flagawaldebitur,
                    'insert' => date('Y-m-d H:i:s')
                );

                $this->prakarsamediumRepo->insertPengajuanPrescreeningSme($refno, $arrdata);

                // bypass kemendagri
                // if (env('BYPASS_PRESCREENING_KEMENDAGRI') == TRUE && $company == '0') {
                //     $CI->libs_brispot->bypassPrescreeningKemendagri($refno, $nik, $branch, $pn, $tgllahir, $gender);
                // }

                // bypass SLIK
                if (env('BYPASS_PRESCREENING_SLIK') == TRUE) {
                    $this->bypassPrescreeningSlikSme($refno, $company, $nama, $tgllahir, $nik, $branch, $pn, $bibr, $slik_cbasid);
                }

                $msgcomplete = "DHN debitur, KEMENDAGRI, SLIK, SICD, SIKP.";

                $objdhndeb = new stdClass;
                $objdhndeb->result = null;
                $objdhn = $objdhndeb;
                $objScreen->dhn = $objdhn;
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

                $objsid = [];
                array_push($objsid, $objsiddeb);

                $objScreen->sidbi = $objsid;
                
                $objsidpers = new stdClass;
                $objsidpers->attachlink = rtrim(env('BRISPOT_MONOLITHIC_URL'), "service") . "docs/SID1/";
                $objsidpers->debitur = $objsid;

                array_push($screenSid, $objsidpers);
            } else {
                /** Start Prescreening DHN */
                    if ($cekpengajuandebitur[0]->dhn == '1') {
                        $datapre = $this->prakarsamediumRepo->getPrescreenDhn($refno, $nama, $tgllahir, $branch, $pn);
                        if (count($datapre) > 0) {
                            $arrres = array();
                            foreach ($datapre  as $rowres) {
                                $datares = $this->cek_JSON($rowres->data) ? json_decode($rowres->data) : $rowres->data;
                                if ($datares->result == "1" && in_array($kategoriNasabah, array($perpanjangan, $deplesi)) == FALSE) {
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
                /** End Prescreening DHN */

                /** Start Prescreening SLIK */
                    $link_file = env('BRISPOT_MONOLITHIC_URL');
                    $new_link_file = rtrim($link_file, '/service');

                    if ($cekpengajuandebitur[0]->slik !== '1') {
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
                    } else {
                        $array_custtype = [];
                        $array_nama_debitur = [];

                        $getPengajuanPrescreen = $this->prakarsamediumRepo->cekPengajuanPrescreeningSmeByRefno($refno);
                        foreach ($getPengajuanPrescreen as $data => $value) {
                            array_push($array_custtype, $value->custtype);
                            $array_nama_debitur[$value->nik] = $value->nama;
                        }

                        $nik_slik = [];
                        $id_host_slik = [];
                        foreach ($slik_selected as $id_slik => $val2) {
                            if ($val2['selected'] == '1' && $val2['id_host'] != '') {
                                array_push($nik_slik, $val2['nik']);
                                array_push($id_host_slik, $val2['id_host']);
                            }
                        }

                        $array_nik = [];
                        $dataUpd = array('selected' => '0');

                        /** Do update selected SLIK */
                        $this->prakarsamediumRepo->updateSelectedSlik($refno, '', '', $dataUpd);

                        $getSlik = $this->prakarsamediumRepo->getPrescreenSlikNew($refno);
                        foreach ($getSlik as $data2 => $value2) {
                            if (in_array($value2->nik, $nik_slik) || in_array($value2->id, $id_host_slik)) {
                                $dataUpd = array('selected' => '1');
                                /** Do update selected SLIK */
                                $this->prakarsamediumRepo->updateSelectedSlik($refno, $value2->nik, $value2->id, $dataUpd);
                            }
                        }

                        $dataPrescreenSlik = $this->prakarsamediumRepo->getPrescreenSlikSmeNoAlias($refno, $tgllahir, $branch, $pn, $nik);
                        if (count($dataPrescreenSlik) > 0) {
                            $arrres = array();
                            $i = 0;

                            foreach ($dataPrescreenSlik  as $rowres) {
                                $datares = $this->cek_JSON($rowres->result) ? json_decode($rowres->result) : $rowres->result;
                                if (isset($array_nama_debitur[$rowres->nik])) {
                                    if ($datares->nama_debitur == null) {
                                        $datares->nama_debitur = $array_nama_debitur[$rowres->nik];
                                    }
                                }

                                if (!empty(array_intersect($array_custtype, ['A', 'S', 'J', 'C', 'Y']))) {
                                    if ($dataPrescreenSlik[0]->selected == '1' && $datares->result == '1.0' && in_array($kategoriNasabah, array($perpanjangan, $deplesi)) == FALSE) {
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

                                        if ($vals->filename === NULL || $vals->filename == "") {
                                            $fileName =  "NIK_" . $nik . "_" . Carbon::now()->format('YmdHis') . ".pdf";
                                        } else {
                                            $fileName = $vals->filename;
                                        }

                                        $tahun = substr(substr($fileName, -18), 0, 4);
                                        $bulan = substr(substr($fileName, -14), 0, 2);
                                        $hari = substr(substr($datares->filename, -12), 0, 2);
                                        $newfilename1 = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                        if (file_exists($new_link_file . "docs/SID1/" . $newfilename1)) {
                                            $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $datares->filename;
                                        } else {
                                            $newfilename = $tahun . '/' . $bulan . '/' . $datares->filename;
                                        }
                                        array_push($arrres, $datares);
                                    }
                                } else {
                                    $datares->id = $rowres->id;
                                    $datares->selected = $rowres->selected;

                                    if ($datares->filename === NULL || $datares->filename == "") {
                                        $fileName =  "NIK_" . $nik . "_" . Carbon::now()->format('YmdHis') . ".pdf";
                                    } else {
                                        $fileName = $datares->filename;
                                    }
                                    $tahun = substr(substr($fileName, -18), 0, 4);
                                    $bulan = substr(substr($fileName, -14), 0, 2);
                                    $hari = substr(substr($fileName, -12), 0, 2);
                                    $newfilename1 = $tahun . '/' . $bulan . '/' . $hari . '/' . $fileName;
                                    if (file_exists($new_link_file . "docs/SID1/" . $newfilename1)) {
                                        $newfilename = $tahun . '/' . $bulan . '/' . $hari . '/' . $fileName;
                                    } else {
                                        $newfilename = $tahun . '/' . $bulan . '/' . $fileName;
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
                    }

                    $objsidpers = new stdClass;
                    $objsidpers->attachlink = $new_link_file . "/docs/SID1/";
                    $objsidpers->debitur = $objsid;
                    array_push($screenSid, $objsidpers);
                /** End Prescreening SLIK */

                /** Start Prescreening Kemendagri */
                    if ($cekpengajuandebitur[0]->kemendagri == '1') {
                        $datapre = $this->prakarsamediumRepo->getPrescreenKemendagri($refno, $nik);
                        if (count($datapre) > 0) {
                            $arrres = array();
                            $i = 0;
                            foreach ($datapre  as $rowres) {
                                $datares = $this->cek_JSON($rowres->data) ? json_decode($rowres->data) : $rowres->data;
                                array_push($arrres, $datares);
                            }
                            $objkemendagri = $datares;
                        } else if ($custtype == 'C') {
                            $objkemdeb = new stdClass;
                            $objkemdeb->nik = $nik;
                            $objkemdeb->namaLengkap = $nama;
                            $objkemendagri = $objkemdeb;
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
                /** End Prescreening Kemendagri */

                /** Start Prescreening SICD */
                    if ($cekpengajuandebitur[0]->sicd == '1') {
                        $array_nik = [];
                        $array_custtype = [];
                        $array_nama_sicd = [];
                    
                        $getPengajuanPrescreen = $this->prakarsamediumRepo->cekPengajuanPrescreeningSmeByRefno($refno);
                        foreach ($getPengajuanPrescreen as $data => $value) {
                            array_push($array_nik, $value->nik);
                            array_push($array_nama_sicd, $value->nama);
                            array_push($array_custtype, $value->custtype);
                        }
                    
                        $datapre = $this->prakarsamediumRepo->getPrescreenSicd($refno, $nama, $tgllahir, $branch, $pn);
                        if (count($datapre) > 0) {
                            $arrres = array();
                            foreach ($datapre  as $rowres) {
                                $datares = $this->cek_JSON($rowres->data) ? json_decode($rowres->data) : $rowres->data;
                                $datares->id = $rowres->id;
                                $no_identitas = $datares->no_identitas;
                                if ((in_array($no_identitas, $array_nik) || $no_identitas == null) && (in_array($rowres->nama, $array_nama_sicd))) {
                                    if (!empty(array_intersect($array_custtype, array('A', 'C')))) {
                                        if (in_array($kategoriNasabah, array($perpanjangan, $deplesi)) == FALSE) {
                                            $isPh = isset($datares->is_ph) &&  $datares->is_ph != "" ? $datares->is_ph : "";
                                            $tglPh = isset($datares->tgl_ph) && $datares->tgl_ph != "" ? $datares->tgl_ph : "";
                                            if (($datares->bikole > 2 || $datares->result == '1') || ($isPh == '1' && $tglPh > date('Y-m-d', strtotime("-730 day", strtotime(date("Y-m-d")))))) {
                                                $cekHasil = '0';
                                                $tolak_sicd = " -Gagal pada SICD";
                                            }
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
                /** End Prescreening SICD */

                $objsicdpers = new stdClass;
                $objsicdpers->debitur = $objsicd;
                array_push($screenScid, $objsicdpers);

                $sikpPic = isset($val['sikp_pic']) && $val['sikp_pic'] != "" ? trim($val['sikp_pic']) : "0";
                if ($cekpengajuandebitur[0]->sikp == '1' || $sikpPic) {
                    $datapre = $this->prakarsamediumRepo->getPrescreenSikp($refno, $nik, $branch, $pn);
                    if (count($datapre) > 0) {
                        $objsikp->debitur = $datapre[0]->data;
                    }
                }
            }
        }

        if ($cekHasil == '0') {
            $keterangan = $keterangan_tolak . '' . $tolak_ps . $tolak_bkpm . $tolak_dhn . $tolak_slik . $tolak_sicd . $tolak_kemendagri;
            $data_ket = !empty($dataPrakarsa->keterangan_lainnya) ? json_decode($dataPrakarsa->keterangan_lainnya) : [];
            $newData = [];
            $newData['tipe']         = 'tolak_prakarsa';
            $newData['keterangan']   = $tolak_ps . $tolak_bkpm . $tolak_dhn . $tolak_slik . $tolak_sicd . $tolak_kemendagri;

            array_push($data_ket, $newData);

            $dataUpd = array('keterangan_lainnya' => json_encode($data_ket));

            /** Do update mst_prakarsa */
            $this->prakarsamediumRepo->updatePrakarsa($refno, $dataUpd);
        } elseif ($cekHasil == '2') {
            $keterangan = $keterangan_override . '' . $override;
        } else {
            $keterangan = $keterangan_lolos;
        }

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
        $resp->sikp = $objsikp;

        $this->output->responseCode = '00';
        $this->output->responseDesc = 'Prescreening selesai.' . '\n' . $warningmsg;
        $this->output->responseData = $resp;

        if (trim($msgcomplete) == '') {
            $this->output->responseCode = '00';
            $this->output->responseDesc = 'Prescreening selesai.' . $warningmsg;
        } else {
            $this->output->responseCode = '04';
            $this->output->responseDesc = 'Prescreening ' . substr($msgcomplete, 0, -2) . ' sedang diproses, silakan cek setiap 10 menit.';
        }

        return response()->json($this->output);
    }
}