"""Owner-controlled copyright, no default identity and no caption licence injection."""
import os,sys
from pathlib import Path
from types import SimpleNamespace
import pytest
os.environ.setdefault('QT_QPA_PLATFORM','offscreen')
TOOL=Path(__file__).resolve().parents[1];sys.path.insert(0,str(TOOL))
import exif_writer,poster,config
import piexif
from PIL import Image

@pytest.mark.parametrize('text',['','Owner supplied copyright'])
def test_metadata_has_only_owner_copyright(text):
 data=piexif.load(exif_writer.build_exif_bytes('A photograph','#test',text))
 assert data['0th'][piexif.ImageIFD.Copyright]==text.encode()
 assert piexif.ImageIFD.Artist not in data['0th']

def test_missing_config_copyright_is_empty(monkeypatch,tmp_path):
 monkeypatch.setattr(config,'_config_path',lambda:str(tmp_path/'config.ini'))
 monkeypatch.setattr(config,'_shared_creds',lambda:None)
 monkeypatch.setattr(config,'_open_secret',lambda value:'')
 assert config.load()['copyright_text']==''

@pytest.mark.parametrize('caption',['','A photograph with words'])
@pytest.mark.parametrize('copyright_text',['','Owner copyright'])
def test_solo_actual_upload_keeps_caption_separate(tmp_path,caption,copyright_text,monkeypatch):
 Image.new('RGB',(40,30),'blue').save(tmp_path/'image.jpg')
 entry=SimpleNamespace(file='image.jpg',title='A photograph',tags='#test',caption=caption,category='',album='',orientation='auto',colors='',alt='',color_mode='')
 sent={}
 import drive
 def upload(**kw):
  sent['drive_exif']=piexif.load(Path(kw['file_path']).read_bytes());return 'https://example.invalid/original.jpg'
 monkeypatch.setattr(drive,'upload',upload)
 class Session:
  def post(self,url,**kw):
   sent.update(kw['data']);sent['exif']=piexif.load(kw['files']['img_file'][1].read())
   return SimpleNamespace(text='success',url=url,raise_for_status=lambda:None)
 client=poster.SnapSmackClient.__new__(poster.SnapSmackClient);client.base_url='https://example.invalid';client.session=Session()
 result=client.post_image(entry,str(tmp_path),poster.SiteData(),copyright_text=copyright_text,export_sharpen='none',drive_service=object())
 assert result.success,result.message
 assert sent['drive_exif']['0th'][piexif.ImageIFD.Copyright]==copyright_text.encode()
 assert piexif.ImageIFD.Artist not in sent['drive_exif']['0th']
 assert sent['desc']==caption
 assert sent['exif']['0th'][piexif.ImageIFD.Copyright]==copyright_text.encode()
 assert piexif.ImageIFD.Artist not in sent['exif']['0th']

def test_gram_does_not_add_metadata_or_caption_text(monkeypatch,tmp_path):
 p=tmp_path/'image.jpg';Image.new('RGB',(30,20)).save(p);before=p.read_bytes();sent={}
 entry=SimpleNamespace(file='image.jpg',title='Title',tags='',caption='Caption',alt='',color_mode='')
 monkeypatch.setattr(poster,'_gram_upload',lambda conn,path: {'path':'fixture.jpg'})
 class Session:
  def post(self,url,**kw):
   sent.update(kw['json']);return SimpleNamespace(status_code=200,json=lambda:{'status':'ok','post_id':0,'split_post_ids':[0]})
 conn=SimpleNamespace(session=Session(),_api=lambda r:r)
 poster.post_gram(conn,entry,str(tmp_path))
 assert sent['body']=='Caption'
 assert p.read_bytes()==before


def test_qt_field_load_profile_save_and_publish(monkeypatch,tmp_path):
 from PySide6.QtWidgets import QApplication,QMessageBox
 import sybu_qt
 from unittest.mock import MagicMock
 fields={k:'' for k in ('url','api_key','last_image_folder','last_manifest_file','gemini_api_key','google_credentials','drive_folder_id','gemini_last_prompt')}
 fields.update(drive_enabled=False,copyright_text='Saved copyright')
 engine=MagicMock();engine.config=dict(fields);engine.profiles_list.return_value=[];engine.config_fields.return_value=fields
 monkeypatch.setattr(config,'_config_path',lambda:str(tmp_path/'saved.ini'))
 monkeypatch.setattr(config,'_shared_creds',lambda:None)
 monkeypatch.setattr(config,'_open_secret',lambda value:'')
 real_save=sybu_qt.sybu_core.Engine.save_config
 engine.save_config.side_effect=lambda values:real_save(engine,values)
 engine.save_config.return_value='';engine.post_preflight.return_value={'count':1,'dest':'fixture'};engine.post_start.return_value={}
 monkeypatch.setattr(sybu_qt.sybu_core,'Engine',lambda:engine)
 app=QApplication.instance() or QApplication([])
 from PySide6.QtGui import QFont,QFontDatabase
 for filename in ('segoeui.ttf','segoeuib.ttf'):QFontDatabase.addApplicationFont('C:/Windows/Fonts/'+filename)
 app.setFont(QFont('Segoe UI',10));app.setStyle('Fusion');app.setStyleSheet(sybu_qt.STYLE)
 w=sybu_qt.Window();w.timer.stop();w.show();app.processEvents()
 assert w.copyright_text.text()=='Saved copyright'
 profile=dict(fields,image_folder='',prompt='');profile['copyright_text']='Profile copyright'
 engine.profile_apply_to_post.return_value=profile
 monkeypatch.setattr(w,'_connect',lambda:None);w._profile('Fixture')
 assert w.copyright_text.text()=='Profile copyright'
 w.copyright_text.setText('');w._save();assert engine.save_config.call_args.args[0]['copyright_text']==''
 assert config.load()['copyright_text']==''
 w.copyright_text.setText('Round trip');w._save();assert config.load()['copyright_text']=='Round trip'
 w.copyright_text.setText('')
 monkeypatch.setattr(w,'_sync_queue',lambda:None);monkeypatch.setattr(QMessageBox,'question',lambda *a:QMessageBox.Yes)
 w._post(False);assert engine.post_start.call_args.args[5]==''
 w.copyright_text.setText('Owner visible value');w._post(False);assert engine.post_start.call_args.args[5]=='Owner visible value'
 assert w.grab().save(str(tmp_path/'copyright-field.png'));w.close()

# ===== SNAPSMACK EOF =====
