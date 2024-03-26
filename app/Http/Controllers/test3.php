<?php

class Controller extends BaseController
{

    public function parsingLasToBrispotPengelolaanKeuangan($paramData, $kategori_nasabah) {
        $this->CI->load->library('libs_kredit');
        $this->CI->load->model('service_model');
        $result = new StdClass();
        $result->Response_code = '';
        $result->Response_message = '';
        try {
            $refno = $this->CI->security->xss_clean(trim($paramData->refno));
            $userid_ao = substr('00000000' . $this->CI->security->xss_clean(trim($paramData->userid_ao)), -8);
            $param_las = new stdClass();
            $param_las->no_rekening = trim($paramData->no_rekening);
            $response_las = $this->CI->libs_kredit->inquiryDataLASRitkom($param_las);
            $this->CI->service_model->insert_activity($userid_ao, 'inquiryDataLASRitkom kirim ke LAS', json_encode($param_las));
//                print_r(json_encode($response_las));
//                exit();
            if (isset($response_las) && $response_las->statusCode == "01") {
                $result_ws = array();
                if (isset($response_las->data) && is_array($response_las->data)) {
                    $currYear = date('Y');
                    $getYearFrom = $currYear - 2;
                    $arrYearLabaNeraca = array();
                    $arrlabarugi = array();
                    $arrneraca = array();
                    for ($i = $getYearFrom; $i <= $currYear; $i++) {
                        array_push($arrYearLabaNeraca, $i);
                        $arrlabarugi[$i] = new stdClass();
                        $arrneraca[$i] = new stdClass();
                    }
                    $content_data_labarugi = "";
                    $content_data_neraca = "";
                    $content_datarekomendasi = "";
                    $id_kredit = trim($paramData->id_kredit);
                    $acctno = $uid = $id_aplikasi = $tp_produk = $cif_las = "";
                    $cif_las = trim($response_las->data[0]->items[0]->fid_cif_las);
                    $tp_produk = trim($response_las->data[0]->items[0]->fid_tp_produk);
                    $id_aplikasi = trim($response_las->data[0]->items[0]->id_aplikasi);
                    $uid = trim($response_las->data[0]->items[0]->fid_uid);
                    $acctno = substr('0000' . $this->CI->security->xss_clean(trim($paramData->no_rekening)), -15);
                    foreach ($response_las->data as $rowdata) {
                        if ($rowdata->data_name == 'data_laba_rugi') { //DONE
                            foreach ($rowdata->items as $rowlabarugi) {
                                $tahun_posisi = $rowlabarugi->TAHUN_POSISI;
                                if (in_array($tahun_posisi, $arrYearLabaNeraca)) {
//                                        echo "<pre>";print_r($rowlabarugi);exit();
                                    $arrlabarugi[$tahun_posisi] = array(
                                        "tahun_posisi" => trim($rowlabarugi->TAHUN_POSISI),
                                        "periode" => "",
                                        "audited" => "",
                                        "tanggal_posisi" => "",
                                        "penjualan_bersih" => (float) trim($rowlabarugi->PENJUALAN_BERSIH),
                                        "hpp" => (float) trim($rowlabarugi->HPP),
                                        "laba_kotor" => (float) trim($rowlabarugi->LABA_KOTOR),
                                        "sewa_dan_sewa_beli" => (float) trim($rowlabarugi->SEWA_DAN_SEWA_BELI),
                                        "biaya_penjualan_umum_adm" => (float) trim($rowlabarugi->BIAYA_PENJUALAN_UMUM_ADM),
                                        "biaya_operasional_lainnya" => (float) trim($rowlabarugi->BIAYA_OPERASIONAL_LAINNYA),
                                        "keuntungan_operasional" => (float) trim($rowlabarugi->KEUNTUNGAN_OPERASIONAL),
                                        "biaya_penyusutan" => (float) trim($rowlabarugi->BIAYA_PENYUSUTAN),
                                        "biaya_amortisasi" => (float) trim($rowlabarugi->BIAYA_AMORTISASI),
                                        "biaya_bunga" => (float) trim($rowlabarugi->BIAYA_BUNGA),
                                        "biaya_pribadi" => (float) trim($rowlabarugi->BIAYA_PRIBADI),
                                        "biaya_non_operasional_lainnya" => (float) trim($rowlabarugi->BIAYA_NON_OPERASIONAL_LAINNYA),
                                        "pendapatan_non_operasional_lainnya" => (float) trim($rowlabarugi->PENDAPATAN_NON_OPERASIONAL_LAINNYA),
                                        "laba_sebelum_pajak" => (float) trim($rowlabarugi->LABA_SEBELUM_PAJAK),
                                        "pajak" => (float) trim($rowlabarugi->PAJAK),
                                        "laba_bersih" => (float) trim($rowlabarugi->LABA_BERSIH),
                                        "angsuran_bunga_1_tahun" => (float) trim($rowlabarugi->ANGSURAN_BUNGA_1_TAHUN),
                                        "angsuran_pokok_1_tahun" => (float) trim($rowlabarugi->ANGSURAN_POKOK_1_TAHUN)
                                    );
                                }
                            }
                            $content_data_labarugi = json_encode($arrlabarugi);
                        } elseif ($rowdata->data_name == 'data_neraca') { //DONE
                            foreach ($rowdata->items as $rowneraca) {
                                $tahun_posisi = strtotime($this->CI->libs_brispot->convertdate(trim($rowneraca->TANGGAL_POSISI)));
                                $tahun_posisi = date("Y", $tahun_posisi);
                                if (in_array($tahun_posisi, $arrYearLabaNeraca)) {
                                    //echo "<pre>";print_r($rowneraca);exit();
                                    $arrneraca[$tahun_posisi] = array(
                                        "tahun_posisi" => trim($tahun_posisi),
                                        "tanah" => (float) trim($rowneraca->TANAH),
                                        "bangunan" => (float) trim($rowneraca->BANGUNAN),
                                        "mesin" => (float) trim($rowneraca->MESIN),
                                        "kendaraan" => (float) trim($rowneraca->KENDARAAN),
                                        "inventaris_kantor" => "",
                                        "peralatan_kantor" => (float) trim($rowneraca->PERALATAN_KANTOR),
                                        "aktiva_tetap_sebelum_penyusutan" => "",
                                        "akumulasi_penyusutan" => (float) trim($rowneraca->AKUMULASI_PENYUSUTAN),
                                        "penempatan_perusahaan_lain" => "",
                                        "investasi_lainnya" => (float) trim($rowneraca->INVESTASI_LAINNYA),
                                        "aktiva_lainnya" => (float) trim($rowneraca->AKTIVA_LAINNYA),
                                        "good_will" => (float) trim($rowneraca->GOOD_WILL),
                                        "aktiva_pasif" => (float) trim($rowneraca->AKTIVA_PASIF),
                                        "kas_bank" => (float) trim($rowneraca->KAS_BANK),
                                        "surat_berharga" => (float) trim($rowneraca->SURAT_BERHARGA),
                                        "piutang_dagang" => (float) trim($rowneraca->PIUTANG_DAGANG),
                                        "persediaan" => (float) trim($rowneraca->PERSEDIAAN),
                                        "uang_muka_pembelian" => "",
                                        "piutang_lainnya" => (float) trim($rowneraca->PIUTANG_LAINNYA),
                                        "biaya_dibayar_dimuka" => (float) trim($rowneraca->BIAYA_DIBAYAR_DIMUKA),
                                        "aktiva_lancar_lainnya" => (float) trim($rowneraca->AKTIVA_LANCAR_LAINNYA),
                                        "aktiva_lancar" => (float) trim($rowneraca->AKTIVA_LANCAR),
                                        "total_aktiva" => (float) trim($rowneraca->TOTAL_AKTIVA),
                                        "hutang_dagang" => (float) trim($rowneraca->HUTANG_DAGANG),
                                        "hutang_bank_jangka_pendek" => (float) trim($rowneraca->HUTANG_BANK_JANGKA_PENDEK),
                                        "kewajiban_yang_masih_harus_dibayar" => (float) trim($rowneraca->KEWAJIBAN_YANG_MASIH_HARUS_DIBAYAR),
                                        "kewajiban_yang_masih_harus_dibayar_pihak_3" => (float) trim($rowneraca->KEWAJIBAN_YANG_MASIH_HARUS_DIBAYAR_PIHAK_3),
                                        "hutang_lain" => (float) trim($rowneraca->HUTANG_LAIN),
                                        "hutang_pajak" => (float) trim($rowneraca->HUTANG_PAJAK),
                                        "hutang_jangka_panjang_segera_jatuh_tempo" => (float) trim($rowneraca->HUTANG_JANGKA_PANJANG_SEGERA_JATUH_TEMPO),
                                        "passiva_lancar" => (float) trim($rowneraca->PASSIVA_LANCAR),
                                        "hutang_bank_jangka_panjang" => (float) trim($rowneraca->HUTANG_BANK_JANGKA_PANJANG),
                                        "pembayaran_uang_muka" => (float) trim($rowneraca->PEMBAYARAN_UANG_MUKA),
                                        "hutang_kepada_persero" => "",
                                        "hutang_jangka_panjang_pada_pihak_3" => (float) trim($rowneraca->HUTANG_JANGKA_PANJANG_PADA_PIHAK_3),
                                        "hutang_jangka_panjang_lain" => "",
                                        "hutang_jangka_panjang" => (float) trim($rowneraca->HUTANG_JANGKA_PANJANG),
                                        "saham_istimewa" => (float) trim($rowneraca->SAHAM_ISTIMEWA),
                                        "modal" => (float) trim($rowneraca->MODAL),
                                        "kelebihan_nilai_modal" => (float) trim($rowneraca->KELEBIHAN_NILAI_MODAL),
                                        "modal_lain" => "",
                                        "prive" => (float) trim($rowneraca->PRIVE),
                                        "revaluasi_asset" => (float) trim($rowneraca->REVALUASI_ASSET),
                                        "cadangan_lainnya" => (float) trim($rowneraca->CADANGAN_LAINNYA),
                                        "laba_ditahan" => (float) trim($rowneraca->LABA_DITAHAN),
                                        "laba_tahun_berjalan" => (float) trim($rowneraca->LABA_TAHUN_BERJALAN),
                                        "total_modal" => (float) trim($rowneraca->TOTAL_MODAL),
                                        "total_hutang" => "",
                                        "total_pasiva_lancar" => "",
                                        "total_passiva" => (float) trim($rowneraca->TOTAL_PASSIVA)
                                    );
                                }
                            }
                            $content_data_neraca = json_encode($arrneraca);
                        } elseif ($rowdata->data_name == 'data_kredit') {
                            //data rekomendasi
                            $objrekomendasi = new stdClass; //DONE
                            if (isset($rowdata->items[0])) {
                                foreach ($rowdata->items as $row_datarekomendasi) {
                                    if (trim($row_datarekomendasi->ID_KREDIT) == $id_kredit) {
                                        $datarekomendasi = $row_datarekomendasi;
                                        //echo "<pre>";print_r($datarekomendasi->FID_APLIKASI);exit();
                                        $objrekomendasi->ID_KREDIT = trim($datarekomendasi->ID_KREDIT);
                                        $objrekomendasi->baru_perpanjangan = trim($datarekomendasi->BARU_PERPANJANGAN);
                                        $objrekomendasi->tujuan_membuka_rek = trim($datarekomendasi->TUJUAN_MEMBUKA_REK);
                                        $objrekomendasi->jangka_waktu = trim($datarekomendasi->JANGKA_WAKTU);
                                        $objrekomendasi->tujuan_penggunaan_kredit = trim($datarekomendasi->TUJUAN_PENGGUNAAN_KREDIT);
                                        $objrekomendasi->penggunaan_kredit = trim($datarekomendasi->PENGGUNAAN_KREDIT);
                                        $objrekomendasi->valuta = trim($datarekomendasi->VALUTA);
                                        $objrekomendasi->plafon_induk = trim($datarekomendasi->PLAFON_INDUK);
                                        $objrekomendasi->provisi_kredit = trim($datarekomendasi->PROVISI_KREDIT);
                                        $objrekomendasi->biaya_administrasi = trim($datarekomendasi->BIAYA_ADMINISTRASI);
                                        $objrekomendasi->penalty = trim($datarekomendasi->PENALTY);
                                        $objrekomendasi->review_suku_bunga = trim($datarekomendasi->REVIEW_SUKU_BUNGA);
                                        $objrekomendasi->suku_bunga = trim($datarekomendasi->SUKU_BUNGA);
                                        $objrekomendasi->commitment_fee = trim($datarekomendasi->COMMITMENT_FEE);
                                        $objrekomendasi->perjanjian_kredit = trim($datarekomendasi->PERJANJIAN_KREDIT);
                                        if (in_array($kategori_nasabah, array("1", "2"))) {
                                            $objrekomendasi->plafon_semula = (!empty($datarekomendasi->ACCRUAL_BALANCE) ? trim($datarekomendasi->ACCRUAL_BALANCE):"0"); //default plafond existing
                                            $objrekomendasi->plafon_tambah_kurang = "0"; //default 0
                                            $objrekomendasi->plafon_baru = trim($datarekomendasi->PLAFON_TAMBAH_KURANG); //
                                        } else {
                                            $objrekomendasi->plafon_semula = trim($datarekomendasi->PLAFON_SEMULA);
                                            $objrekomendasi->plafon_tambah_kurang = trim($datarekomendasi->PLAFON_TAMBAH_KURANG);
                                            $objrekomendasi->plafon_baru = (isset($datarekomendasi->PLAFON_SEMULA) ? intval(trim($datarekomendasi->PLAFON_SEMULA)) : 0) + (isset($datarekomendasi->PLAFON_TAMBAH_KURANG) ? intval(trim($datarekomendasi->PLAFON_TAMBAH_KURANG)) : 0);
                                        }
                                        $objrekomendasi->bentuk_kredit = trim($datarekomendasi->BENTUK_KREDIT);
                                        $objrekomendasi->obyek_dibiayai = trim($datarekomendasi->OBYEK_DIBIAYAI);
                                        $objrekomendasi->alasan_permohonan_kredit = trim($datarekomendasi->ALASAN_PERMOHONAN_KREDIT);
                                        $objrekomendasi->syarat_lainnya = trim($datarekomendasi->SYARAT_LAINNYA);
                                        $objrekomendasi->kurs = trim($datarekomendasi->KURS);
                                        $objrekomendasi->sumber_info_kurs = trim($datarekomendasi->SUMBER_INFO_KURS);
                                        $objrekomendasi->status_takeover = trim($datarekomendasi->STATUS_TAKEOVER);
                                        $objrekomendasi->bank_asal_takeover = trim($datarekomendasi->BANK_ASAL_TAKEOVER);
                                        $objrekomendasi->laba_bersih = trim($datarekomendasi->LABA_BERSIH);
                                        $objrekomendasi->penyusutan = trim($datarekomendasi->PENYUSUTAN);
                                        $objrekomendasi->prive = trim($datarekomendasi->PRIVE);
                                        $objrekomendasi->servicing_fee = "0";
                                        $objrekomendasi->DendaPelunasanMaju = "0";
                                        $objrekomendasi->tanggal_angsuran_pertama = "";
                                        $objrekomendasi->id_val = "";
                                        $filter = array("LOANTYPE" => trim($datarekomendasi->KODE_FASILITAS), "SANDI_STP" => trim($datarekomendasi->SANDI_STP)); //how to obtain loan_type
                                        $loanTypeNew = $this->CI->service_model->get_produk_loantype_new($filter);
                                        $objrekomendasi->loan_type = (isset($loanTypeNew->LOANTYPE) && $loanTypeNew->LOANTYPE ? trim($loanTypeNew->LOANTYPE) : trim($datarekomendasi->KODE_FASILITAS));
                                        $objrekomendasi->loan_type_id = (isset($loanTypeNew->ID) && $loanTypeNew->ID ? trim($loanTypeNew->ID) : "");
                                        $objrekomendasi->loan_type_desc = (isset($loanTypeNew->KETERANGAN_SANDI_STP) && $loanTypeNew->KETERANGAN_SANDI_STP ? trim($loanTypeNew->KETERANGAN_SANDI_STP) : "-");
                                        $objrekomendasi->budidaya = trim($datarekomendasi->BUDIDAYA);
                                    }
                                }
                            } else {
                                $objrekomendasi->ID_KREDIT = "";
                                $objrekomendasi->baru_perpanjangan = "";
                                $objrekomendasi->tujuan_membuka_rek = "";
                                $objrekomendasi->jangka_waktu = "";
                                $objrekomendasi->tujuan_penggunaan_kredit = "";
                                $objrekomendasi->penggunaan_kredit = "";
                                $objrekomendasi->valuta = "";
                                $objrekomendasi->plafon_induk = "";
                                $objrekomendasi->provisi_kredit = "";
                                $objrekomendasi->biaya_administrasi = "0";
                                $objrekomendasi->penalty = "";
                                $objrekomendasi->review_suku_bunga = "";
                                $objrekomendasi->suku_bunga = "";
                                $objrekomendasi->commitment_fee = "";
                                $objrekomendasi->perjanjian_kredit = "";
                                $objrekomendasi->plafon_semula = "0";
                                $objrekomendasi->plafon_tambah_kurang = "0";
                                $objrekomendasi->plafon_baru = "0";
                                $objrekomendasi->bentuk_kredit = "";
                                $objrekomendasi->obyek_dibiayai = "";
                                $objrekomendasi->alasan_permohonan_kredit = "";
                                $objrekomendasi->syarat_lainnya = "";
                                $objrekomendasi->kurs = "";
                                $objrekomendasi->sumber_info_kurs = "";
                                $objrekomendasi->status_takeover = "";
                                $objrekomendasi->bank_asal_takeover = "";
                                $objrekomendasi->laba_bersih = "";
                                $objrekomendasi->penyusutan = "";
                                $objrekomendasi->prive = "";
                                $objrekomendasi->servicing_fee = "0";
                                $objrekomendasi->DendaPelunasanMaju = "0";
                                $objrekomendasi->tanggal_angsuran_pertama = "";
                                $objrekomendasi->id_val = "";
                                $objrekomendasi->loan_type_id = "";
                                $objrekomendasi->loan_type = "";
                                $objrekomendasi->loan_type_desc = "";
                                $objrekomendasi->budidaya = "";
                            }
                            $responsedata->content_datarekomendasi[1] = $objrekomendasi;
                            $content_datarekomendasi = json_encode($responsedata->content_datarekomendasi);
                        }
                    }

                    //content_data_kebutuhan_kredit
                    $objdatakebutuhan = new stdClass();
                    $objdatakebutuhan->tujuan_penggunaan = "";
                    $objdatakebutuhan->jenis_fasilitas = "";
                    $objdatakebutuhan->keterangan_fasilitas = "";
                    $objdatakebutuhan->pendekatan_perhitungan = "";
                    $objdatakebutuhan->suku_bunga = "";
                    $objdatakebutuhan->jangka_waktu = "";
                    $objdatakebutuhan->mata_uang = "";
                    $objdatakebutuhan->kurs = "";
                    $objdatakebutuhan->sumber_nilai_kurs = "";
                    $objdatakebutuhan->periode_perputaran_piutang = "";
                    $objdatakebutuhan->periode_perputaran_persedian = "";
                    $objdatakebutuhan->periode_laporan_keuangan = "";
                    $objdatakebutuhan->hutang_dagang = "";
                    $objdatakebutuhan->kas_minimum = "";
                    $objdatakebutuhan->rumus_biaya = "";
                    $objdatakebutuhan->total_biaya = "";
                    $objdatakebutuhan->rincian_biaya = "";
                    $objdatakebutuhan->dor = "";
                    $objdatakebutuhan->nwc = "";
                    $objdatakebutuhan->proyeksi_peningkatan_biaya = "";
                    $objdatakebutuhan->termin = "";
                    $objdatakebutuhan->pajak_konstruksi = "";
                    $objdatakebutuhan->nilai_proyek = "";
                    $objdatakebutuhan->keuntungan_konstruksi = "";
                    $objdatakebutuhan->uang_muka = "";
                    $objdatakebutuhan->ope_expor = "";
                    $objdatakebutuhan->penjualan_expor = "";
                    $objdatakebutuhan->target_expor = "";
                    $objdatakebutuhan->perputaran_expor = "";
                    $objdatakebutuhan->wcto_kmki = "";
                    $objdatakebutuhan->harga_pokok_kmki = "";
                    $objdatakebutuhan->peningkatan_biaya_kmki = "";
                    $objdatakebutuhan->periode_keuangan_kmki = "";
                    $objdatakebutuhan->biaya_hidup_cost = "";
                    $objdatakebutuhan->biaya_produksi_cost = "";
                    $objdatakebutuhan->besar_fasilitas_lainya = "";
                    $objdatakebutuhan->max_jumlah_kredit = "";
                    $objdatakebutuhan->max_kebutuhan_valas = "";
                    $objdatakebutuhan->kredit_diberikan = "";
                    $kebutuhanKredit[1] = $objdatakebutuhan;
                    $content_data_kebutuhan_kredit = json_encode($kebutuhanKredit);

                    //content_data_char_cap
                    $objdatacharcap = new stdClass();
                    $objdatacharcap->mutasi_bank_bri = json_encode(array());
                    $objdatacharcap->mutasi_bank_lainya = json_encode(array());
                    $objdatacharcap->ratas_penjualan_bank_bri = "";
                    $objdatacharcap->ratas_penjualan_bank_lainya = "";
                    $objdatacharcap->analisa_char = "";
                    $objdatacharcap->analisa_produksi = "";
                    $objdatacharcap->analisa_manajemen = "";
                    $objdatacharcap->analisa_pemasaran = "";
                    $objdatacharcap->analisa_persaingan = "";
                    $objdatacharcap->analisa_perencanaan_bisnis = "";

                    $content_data_char_cap = json_encode($objdatacharcap);
                }
                $arrdata = array(
                    'refno' => $refno,
                    'tipe' => $kategori_nasabah, //testing
                    'content_data_labarugi' => $content_data_labarugi,
                    'content_data_neraca' => $content_data_neraca,
                    'content_data_rekomendasi' => $content_datarekomendasi,
                    'content_data_kebutuhan_kredit' => $content_data_kebutuhan_kredit,
                    'content_data_char_cap' => $content_data_char_cap,
                    'tahun_berjalan' => date('Y')
                );
                $result = $arrdata;
            } else {
                throw new Exception(isset($response_las->statusDesc) ? $response_las->statusDesc : "Gagal mengolah data LAS");
            }
        } catch (SoapFault $f) {
            $result->Response_code = '99';
            $result->Response_message = $f->getMessage();
        } catch (Exception $e) {
            $result->Response_code = '99';
            $result->Response_message = $e->getMessage();
        }
        $this->CI->activity_model->insert_activity_libs_kredit('parsingLasToBrispotPengelolaanKeuangan', isset($paramData) ? json_encode($paramData) : NULL, isset($result) ? json_encode($result) : NULL);
        return($result);
    }
}

$kategoriNasabah = trim($get_prakarsa->kategori_nasabah);
$perpanjangan_deplesi = ["1", "4"];
$objpsdeb = new stdClass;

    if(in_array($kategoriNasabah, $perpanjangan_deplesi)){
        $result_prescreening = '1';
        $objpsdeb->flag_override = "N";
    } 
    
    elseif($kategoriNasabah == '2' || $kode_ps == '4' && in_array($tp_produk, ['49', '50'])){

        $flag_override = '1';
        $result_prescreening = '1';
        $cekHasil = '2';
        $override .= " -Pasar sasaran masuk kategori merah";
        $objpsdeb->flag_override = "Y";     

    } else {
        $result_prescreening = '1';
        $objpsdeb->flag_override = "N";
    }