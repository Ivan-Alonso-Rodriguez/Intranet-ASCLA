"""Generate synthetic browser fixtures; no personal responses. Requires reportlab."""
from pathlib import Path
import os,zipfile
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import landscape,A4
from reportlab.platypus import Table,TableStyle

out=Path(os.getenv('ASCLA_INTEREST_IMPORT_OUT','test-results/interest-import-1111'))/'fixtures'
out.mkdir(parents=True,exist_ok=True)
rows=[['Nombre','Apellido','Correo','Evento'],['Persona','Desconocida','','Laboratorio Ciberseguridad'],['Maria Alejandra','Valverde','','Laboratorio Ciberseguridad'],['Pedro Enrique','Villanueva','','Laboratorio Ciberseguridad']]
c=canvas.Canvas(str(out/'forms.pdf'),pagesize=landscape(A4))
for data in [rows[:3],[rows[0],rows[3]]]:
    table=Table(data,colWidths=[140,130,170,290],rowHeights=30)
    table.setStyle(TableStyle([('GRID',(0,0),(-1,-1),.5,'#778899'),('BACKGROUND',(0,0),(-1,0),'#ddeeff'),('FONTNAME',(0,0),(-1,-1),'Helvetica'),('FONTSIZE',(0,0),(-1,-1),10)]))
    table.wrapOn(c,740,500);table.drawOn(c,40,420);c.showPage()
c.save()
html='<html><head><script>globalThis.unsafeImport=1</script><link href="https://example.invalid/no-load.css" rel="stylesheet"></head><body><table><tr><th></th><th>A</th><th>B</th><th>C</th><th>D</th></tr>'+''.join('<tr><th>'+str(i+1)+'</th>'+''.join('<td>'+v+'</td>' for v in row)+'</tr>' for i,row in enumerate(rows))+'</table><img src="https://example.invalid/no-load.png" onerror="globalThis.unsafeImport=1"></body></html>'
(out/'forms.html').write_text(html,encoding='utf-8')
with zipfile.ZipFile(out/'forms.zip','w',zipfile.ZIP_DEFLATED) as z:
    z.writestr('Respuestas.html',html);z.writestr('resources/sheet.css','x'*1000000)
with zipfile.ZipFile(out/'multiple.zip','w',zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.html',html);z.writestr('b.html',html)
with zipfile.ZipFile(out/'bomb.zip','w',zipfile.ZIP_DEFLATED) as z:z.writestr('a.html','x'*1000000)
c=canvas.Canvas(str(out/'narrative.pdf'));c.drawString(40,750,'A narrative document without a response table');c.save()
c=canvas.Canvas(str(out/'merged-identity.pdf'),pagesize=landscape(A4))
table=Table([rows[0],['A very long nameJoinedSurname beyond its column','','','Board']],colWidths=[140,130,170,290],rowHeights=30)
table.setStyle(TableStyle([('GRID',(0,0),(-1,-1),.5,'#778899')]))
table.wrapOn(c,740,500);table.drawOn(c,40,420);c.save()
print('Synthetic PDF/HTML/ZIP fixtures ready')
