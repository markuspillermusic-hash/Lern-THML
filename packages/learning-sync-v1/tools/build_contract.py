"""Build the private, declarative work contract from authored student markup.

Only field labels, text-work sources and concept-map definitions are included.
No answer key, teacher planning or credentials are part of this format.
"""
import argparse
import json
import re
from html.parser import HTMLParser
from pathlib import Path


class ContractParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.fields, self.materials, self.drawings, self.maps = {}, {}, [], {}
        self.stack=[]
        self.capture=[]

    def handle_starttag(self,tag,attributes):
        attrs=dict(attributes)
        parent=self.stack[-1] if self.stack else {'teacher':False,'map_id':None}
        teacher=parent['teacher'] or attrs.get('data-rolle')=='lehrer'
        current={'tag':tag,'teacher':teacher,'map_id':attrs.get('data-concept-map-id') or parent['map_id']}
        if not teacher:
            if 'data-save' in attrs:self.fields[attrs['data-save']]=attrs.get('aria-label') or attrs['data-save']
            if 'data-drawing-id' in attrs:self.drawings.append(attrs['data-drawing-id'])
            if 'data-learning-highlighter-id' in attrs:
                self.capture.append({'depth':len(self.stack),'tag':tag,'kind':'text','id':attrs['data-learning-highlighter-id'],'text':[]})
            if 'data-concept-map-config' in attrs:
                self.capture.append({'depth':len(self.stack),'tag':tag,'kind':'map','id':current['map_id'],'text':[]})
        if tag not in {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}:self.stack.append(current)

    def handle_endtag(self,tag):
        for i in range(len(self.stack)-1,-1,-1):
            if self.stack[i]['tag']==tag:
                for capture in self.capture[:]:
                    if capture['depth']>=i:
                        text=''.join(capture['text'])
                        if capture['kind']=='map':self.maps[capture['id']]=json.loads(text)
                        else:self.materials[capture['id']]={'text':text,'label':capture['id']}
                        self.capture.remove(capture)
                self.stack=self.stack[:i]
                break

    def handle_data(self,data):
        if self.stack and self.stack[-1]['teacher']:return
        for capture in self.capture:capture['text'].append(data)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('source',type=Path);parser.add_argument('module_js',type=Path);parser.add_argument('output',type=Path)
    parser.add_argument('--module-id',required=True)
    args=parser.parse_args()
    contract=ContractParser();contract.feed(args.source.read_text(encoding='utf-8-sig'))
    source=args.module_js.read_text(encoding='utf-8-sig')
    labels=re.search(r'var labels = \{(.*?)\n  \};',source,re.S)
    if labels:
        for key,value in re.findall(r'(\w+):\s*"([^"\n]*)"',labels.group(1)):
            if key in contract.fields:contract.fields[key]=value
    result={'version':1,'moduleId':args.module_id,'fields':contract.fields,'materials':contract.materials,'drawings':sorted(set(contract.drawings)),'maps':contract.maps}
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f'Learning contract: {len(contract.fields)} fields, {len(contract.materials)} materials, {len(contract.maps)} maps')


if __name__=='__main__':main()
